<?php
/**
 * ConvertToDeposit.php
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

namespace FireflyIII\TransactionRules\Actions;

use DB;
use FireflyIII\Events\TriggeredAuditLog;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use JsonException;
use Illuminate\Support\Facades\Log;

/**
 *
 * Class ConvertToDeposit
 */
class ConvertToDeposit implements ActionInterface
{
    private RuleAction $action;

    /**
     * TriggerInterface constructor.
     *
     * @param  RuleAction  $action
     */
    public function __construct(RuleAction $action)
    {
        $this->action = $action;
    }

    /**
     * @inheritDoc
     * @throws FireflyException
     * @throws JsonException
     */
    public function actOnArray(array $journal): bool
    {
        $groupCount = TransactionJournal::where('transaction_group_id', $journal['transaction_group_id'])->count();
        if ($groupCount > 1) {
            Log::error(sprintf('Group #%d has more than one transaction in it, cannot convert to deposit.', $journal['transaction_group_id']));
            return false;
        }

        Log::debug(sprintf('Convert journal #%d to deposit.', $journal['transaction_journal_id']));
        $type = $journal['transaction_type_type'];
        if (TransactionType::DEPOSIT === $type) {
            Log::error(sprintf('Journal #%d is already a deposit (rule #%d).', $journal['transaction_journal_id'], $this->action->rule_id));

            return false;
        }

        if (TransactionType::WITHDRAWAL === $type) {
            Log::debug('Going to transform a withdrawal to a deposit.');
            $object = TransactionJournal::where('user_id', $journal['user_id'])->find($journal['transaction_journal_id']);
            event(new TriggeredAuditLog($this->action->rule, $object, 'update_transaction_type', TransactionType::WITHDRAWAL, TransactionType::DEPOSIT));

            return $this->convertWithdrawalArray($journal);
        }
        if (TransactionType::TRANSFER === $type) {
            $object = TransactionJournal::where('user_id', $journal['user_id'])->find($journal['transaction_journal_id']);
            event(new TriggeredAuditLog($this->action->rule, $object, 'update_transaction_type', TransactionType::TRANSFER, TransactionType::DEPOSIT));
            Log::debug('Going to transform a transfer to a deposit.');

            return $this->convertTransferArray($journal);
        }

        return false;
    }

    /**
     * Input is a withdrawal from A to B
     * Is converted to a deposit from C to A.
     *
     * @param  array  $journal
     *
     * @return bool
     * @throws FireflyException
     * @throws JsonException
     */
    private function convertWithdrawalArray(array $journal): bool
    {
        $user = User::find($journal['user_id']);
        // find or create revenue account.
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        // get the action value, or use the original destination name in case the action value is empty:
        // this becomes a new or existing (revenue) account, which is the source of the new deposit.
        $opposingName = '' === $this->action->action_value ? $journal['destination_account_name'] : $this->action->action_value;
        // we check all possible source account types if one exists:
        $validTypes = config('firefly.expected_source_types.source.Deposit');
        $opposingAccount    = $repository->findByName($opposingName, $validTypes);
        if (null === $opposingAccount) {
            $opposingAccount = $factory->findOrCreate($opposingName, AccountType::REVENUE);
        }

        Log::debug(sprintf('ConvertToDeposit. Action value is "%s", new opposing name is "%s"', $this->action->action_value, $journal['destination_account_name']));

        // update the source transaction and put in the new revenue ID.
        DB::table('transactions')
          ->where('transaction_journal_id', '=', $journal['transaction_journal_id'])
          ->where('amount', '<', 0)
          ->update(['account_id' => $opposingAccount->id]);

        // update the destination transaction and put in the original source account ID.
        DB::table('transactions')
          ->where('transaction_journal_id', '=', $journal['transaction_journal_id'])
          ->where('amount', '>', 0)
          ->update(['account_id' => $journal['source_account_id']]);

        // change transaction type of journal:
        $newType = TransactionType::whereType(TransactionType::DEPOSIT)->first();

        DB::table('transaction_journals')
          ->where('id', '=', $journal['transaction_journal_id'])
          ->update(['transaction_type_id' => $newType->id, 'bill_id' => null]);

        Log::debug('Converted withdrawal to deposit.');

        return true;
    }

    /**
     * Input is a transfer from A to B.
     * Output is a deposit from C to B.
     * The source account is replaced.
     *
     * @param  array  $journal
     *
     * @return bool
     * @throws FireflyException
     * @throws JsonException
     */
    private function convertTransferArray(array $journal): bool
    {
        $user = User::find($journal['user_id']);
        // find or create revenue account.
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        // get the action value, or use the original source name in case the action value is empty:
        // this becomes a new or existing (revenue) account, which is the source of the new deposit.
        $opposingName = '' === $this->action->action_value ? $journal['source_account_name'] : $this->action->action_value;
        // we check all possible source account types if one exists:
        $validTypes = config('firefly.expected_source_types.source.Deposit');
        $opposingAccount    = $repository->findByName($opposingName, $validTypes);
        if (null === $opposingAccount) {
            $opposingAccount = $factory->findOrCreate($opposingName, AccountType::REVENUE);
        }

        Log::debug(sprintf('ConvertToDeposit. Action value is "%s", revenue name is "%s"', $this->action->action_value, $journal['source_account_name']));

        // update source transaction(s) to be revenue account
        DB::table('transactions')
          ->where('transaction_journal_id', '=', $journal['transaction_journal_id'])
          ->where('amount', '<', 0)
          ->update(['account_id' => $opposingAccount->id]);

        // change transaction type of journal:
        $newType = TransactionType::whereType(TransactionType::DEPOSIT)->first();

        DB::table('transaction_journals')
          ->where('id', '=', $journal['transaction_journal_id'])
          ->update(['transaction_type_id' => $newType->id, 'bill_id' => null]);

        Log::debug('Converted transfer to deposit.');

        return true;
    }
}
