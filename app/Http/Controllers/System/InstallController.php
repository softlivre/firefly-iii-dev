<?php
/**
 * InstallController.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\System;

use Artisan;
use Cache;
use Exception;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Http\Controllers\GetConfigurationData;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\RSA;

/**
 * Class InstallController
 *

 */
class InstallController extends Controller
{
    use GetConfigurationData;

    public const BASEDIR_ERROR   = 'Firefly III cannot execute the upgrade commands. It is not allowed to because of an open_basedir restriction.';
    public const FORBIDDEN_ERROR = 'Internal PHP function "proc_close" is disabled for your installation. Auto-migration is not possible.';
    public const OTHER_ERROR     = 'An unknown error prevented Firefly III from executing the upgrade commands. Sorry.';
    private string $lastError;
    private array  $upgradeCommands;

    /**
     * InstallController constructor.
     */
    public function __construct()
    {
        // empty on purpose.
        $this->upgradeCommands = [
            // there are 3 initial commands
            'migrate'                                  => ['--seed' => true, '--force' => true],
            'firefly-iii:fix-pgsql-sequences'          => [],
            'firefly-iii:decrypt-all'                  => [],
            'firefly-iii:restore-oauth-keys'           => [],
            'generate-keys'                            => [], // an exception :(

            // upgrade commands
            'firefly-iii:transaction-identifiers'      => [],
            'firefly-iii:migrate-to-groups'            => [],
            'firefly-iii:account-currencies'           => [],
            'firefly-iii:transfer-currencies'          => [],
            'firefly-iii:other-currencies'             => [],
            'firefly-iii:migrate-notes'                => [],
            'firefly-iii:migrate-attachments'          => [],
            'firefly-iii:bills-to-rules'               => [],
            'firefly-iii:bl-currency'                  => [],
            'firefly-iii:cc-liabilities'               => [],
            'firefly-iii:back-to-journals'             => [],
            'firefly-iii:rename-account-meta'          => [],
            'firefly-iii:migrate-recurrence-meta'      => [],
            'firefly-iii:migrate-tag-locations'        => [],
            'firefly-iii:migrate-recurrence-type'      => [],
            'firefly-iii:upgrade-liabilities'          => [],
            'firefly-iii:liabilities-600'              => [],

            // verify commands
            'firefly-iii:fix-piggies'                  => [],
            'firefly-iii:create-link-types'            => [],
            'firefly-iii:create-access-tokens'         => [],
            'firefly-iii:remove-bills'                 => [],
            'firefly-iii:fix-negative-limits'          => [],
            'firefly-iii:enable-currencies'            => [],
            'firefly-iii:fix-transfer-budgets'         => [],
            'firefly-iii:fix-uneven-amount'            => [],
            'firefly-iii:delete-zero-amount'           => [],
            'firefly-iii:delete-orphaned-transactions' => [],
            'firefly-iii:delete-empty-journals'        => [],
            'firefly-iii:delete-empty-groups'          => [],
            'firefly-iii:fix-account-types'            => [],
            'firefly-iii:fix-account-order'            => [],
            'firefly-iii:rename-meta-fields'           => [],
            'firefly-iii:fix-ob-currencies'            => [],
            'firefly-iii:fix-long-descriptions'        => [],
            'firefly-iii:fix-recurring-transactions'   => [],
            'firefly-iii:unify-group-accounts'         => [],
            'firefly-iii:fix-transaction-types'        => [],
            'firefly-iii:fix-frontpage-accounts'       => [],
            'firefly-iii:fix-ibans'                    => [],
            'firefly-iii:create-group-memberships'     => [],
            'firefly-iii:upgrade-group-information'    => [],

            // final command to set the latest version in DB
            'firefly-iii:set-latest-version'           => ['--james-is-cool' => true],
            'firefly-iii:verify-security-alerts'       => [],
        ];

        $this->lastError = '';
    }

    /**
     * Show index.
     *
     * @return Factory|View
     */
    public function index()
    {
        app('view')->share('FF_VERSION', config('firefly.version'));
        // index will set FF3 version.
        app('fireflyconfig')->set('ff3_version', (string)config('firefly.version'));

        // set new DB version.
        app('fireflyconfig')->set('db_version', (int)config('firefly.db_version'));

        return view('install.index');
    }

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function runCommand(Request $request): JsonResponse
    {
        $requestIndex = (int)$request->get('index');
        $response     = [
            'hasNextCommand' => false,
            'done'           => true,
            'previous'       => null,
            'error'          => false,
            'errorMessage'   => null,
        ];

        Log::debug(sprintf('Will now run commands. Request index is %d', $requestIndex));
        $indexes = array_values(array_keys($this->upgradeCommands));
        if(array_key_exists($requestIndex, $indexes)) {
            $command = $indexes[$requestIndex];
            $parameters = $this->upgradeCommands[$command];
            Log::debug(sprintf('Will now execute command "%s" with parameters', $command), $parameters);
            try {
                $result = $this->executeCommand($command, $parameters);
            } catch (FireflyException $e) {
                Log::error($e->getMessage());
                Log::error($e->getTraceAsString());
                if (strpos($e->getMessage(), 'open_basedir restriction in effect')) {
                    $this->lastError = self::BASEDIR_ERROR;
                }
                $result          = false;
                $this->lastError = sprintf('%s %s', self::OTHER_ERROR, $e->getMessage());
            }
            if (false === $result) {
                $response['errorMessage'] = $this->lastError;
                $response['error']        = true;
                return response()->json($response);
            }
            $response['hasNextCommand'] = array_key_exists($requestIndex + 1, $indexes);
            $response['previous']       = $command;
        }
        return response()->json($response);
    }

    /**
     * @param  string  $command
     * @param  array  $args
     * @return bool
     * @throws FireflyException
     */
    private function executeCommand(string $command, array $args): bool
    {
        Log::debug(sprintf('Will now call command %s with args.', $command), $args);
        try {
            if ('generate-keys' === $command) {
                $this->keys();
            }
            if ('generate-keys' !== $command) {
                Artisan::call($command, $args);
                Log::debug(Artisan::output());
            }
        } catch (Exception $e) { // intentional generic exception
            throw new FireflyException($e->getMessage(), 0, $e);
        }
        // clear cache as well.
        Cache::clear();
        Preferences::mark();

        return true;
    }

    /**
     * Create specific RSA keys.
     */
    public function keys(): void
    {
        // switch on PHP version.
        $keys = [];
        // switch on class existence.
        Log::info('Will run PHP8 code.');
        $keys = RSA::createKey(4096);

        [$publicKey, $privateKey] = [
            Passport::keyPath('oauth-public.key'),
            Passport::keyPath('oauth-private.key'),
        ];

        if (file_exists($publicKey) || file_exists($privateKey)) {
            return;
        }

        file_put_contents($publicKey, $keys['publickey']);
        file_put_contents($privateKey, $keys['privatekey']);
    }
}
