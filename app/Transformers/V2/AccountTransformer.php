<?php

/*
 * AccountTransformer.php
 * Copyright (c) 2022 james@firefly-iii.org
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

namespace FireflyIII\Transformers\V2;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Class AccountTransformer
 */
class AccountTransformer extends AbstractTransformer
{
    private array                $accountMeta;
    private array                $balances;
    private array                $currencies;
    private ?TransactionCurrency $currency;

    /**
     * @inheritDoc
     * @throws FireflyException
     */
    public function collectMetaData(Collection $objects): void
    {
        $this->currency    = null;
        $this->currencies  = [];
        $this->accountMeta = [];
        $this->balances    = app('steam')->balancesByAccounts($objects, $this->getDate());
        $repository        = app(CurrencyRepositoryInterface::class);
        $this->currency    = app('amount')->getDefaultCurrency();

        // get currencies:
        $accountIds  = $objects->pluck('id')->toArray();
        $meta        = AccountMeta::whereIn('account_id', $accountIds)
                                  ->where('name', 'currency_id')
                                  ->get(['account_meta.id', 'account_meta.account_id', 'account_meta.name', 'account_meta.data']);
        $currencyIds = $meta->pluck('data')->toArray();

        $currencies = $repository->getByIds($currencyIds);
        foreach ($currencies as $currency) {
            $id                    = (int)$currency->id;
            $this->currencies[$id] = $currency;
        }
        foreach ($meta as $entry) {
            $id                                   = (int)$entry->account_id;
            $this->accountMeta[$id][$entry->name] = $entry->data;
        }
    }

    /**
     * @return Carbon
     */
    private function getDate(): Carbon
    {
        $date = today(config('app.timezone'));
        if (null !== $this->parameters->get('date')) {
            $date = $this->parameters->get('date');
        }

        return $date;
    }

    /**
     * Transform the account.
     *
     * @param  Account  $account
     *
     * @return array
     */
    public function transform(Account $account): array
    {
        //$fullType    = $account->accountType->type;
        //$accountType = (string) config(sprintf('firefly.shortNamesByFullName.%s', $fullType));
        $id = (int)$account->id;

        // no currency? use default
        $currency = $this->currency;
        if (0 !== (int)$this->accountMeta[$id]['currency_id']) {
            $currency = $this->currencies[(int)$this->accountMeta[$id]['currency_id']];
        }

        return [
            'id'                      => (string)$account->id,
            'created_at'              => $account->created_at->toAtomString(),
            'updated_at'              => $account->updated_at->toAtomString(),
            'active'                  => $account->active,
            //'order'                   => $order,
            'name'                    => $account->name,
            //            'type'                    => strtolower($accountType),
            //            'account_role'            => $accountRole,
            'currency_id'             => $currency->id,
            'currency_code'           => $currency->code,
            'currency_symbol'         => $currency->symbol,
            'currency_decimal_places' => $currency->decimal_places,
            'current_balance'         => $this->balances[$id] ?? null,
            'current_balance_date'    => $this->getDate(),
            //            'notes'                   => $this->repository->getNoteText($account),
            //            'monthly_payment_date'    => $monthlyPaymentDate,
            //            'credit_card_type'        => $creditCardType,
            //            'account_number'          => $this->repository->getMetaValue($account, 'account_number'),
            'iban'                    => '' === $account->iban ? null : $account->iban,
            //            'bic'                     => $this->repository->getMetaValue($account, 'BIC'),
            //            'virtual_balance'         => number_format((float) $account->virtual_balance, $decimalPlaces, '.', ''),
            //            'opening_balance'         => $openingBalance,
            //            'opening_balance_date'    => $openingBalanceDate,
            //            'liability_type'          => $liabilityType,
            //            'liability_direction'     => $liabilityDirection,
            //            'interest'                => $interest,
            //            'interest_period'         => $interestPeriod,
            //            'current_debt'            => $this->repository->getMetaValue($account, 'current_debt'),
            //            'include_net_worth'       => $includeNetWorth,
            //            'longitude'               => $longitude,
            //            'latitude'                => $latitude,
            //            'zoom_level'              => $zoomLevel,
            'links'                   => [
                [
                    'rel' => 'self',
                    'uri' => '/accounts/'.$account->id,
                ],
            ],
        ];
    }
}
