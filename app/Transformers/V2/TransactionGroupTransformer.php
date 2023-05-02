<?php

/*
 * TransactionGroupTransformer.php
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
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\TransactionType;
use FireflyIII\Support\Http\Api\ConvertsExchangeRates;
use FireflyIII\Support\NullArrayObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class TransactionGroupTransformer
 */
class TransactionGroupTransformer extends AbstractTransformer
{
    use ConvertsExchangeRates;

    private array               $currencies = [];
    private TransactionCurrency $default;
    private array               $meta;

    /**
     * @inheritDoc
     */
    public function collectMetaData(Collection $objects): void
    {
        // start with currencies:
        $currencies = [];
        $journals   = [];
        /** @var array $object */
        foreach ($objects as $object) {
            foreach ($object['sums'] as $sum) {
                $id = $sum['currency_id'];
                if (!array_key_exists($id, $currencies)) {
                    $currencyObject  = TransactionCurrency::find($sum['currency_id']);
                    $currencies[$id] = $currencyObject;
                }
            }
            /** @var array $transaction */
            foreach ($object['transactions'] as $transaction) {
                $id            = $transaction['transaction_journal_id'];
                $journals[$id] = [];
            }
        }
        $this->currencies = $currencies;
        $this->default    = app('amount')->getDefaultCurrency();

        // grab meta for all journals:
        $meta = TransactionJournalMeta::whereIn('transaction_journal_id', array_keys($journals))->get();
        /** @var TransactionJournalMeta $entry */
        foreach ($meta as $entry) {
            $id                            = (int)$entry->transaction_journal_id;
            $this->meta[$id][$entry->name] = $entry->data;
        }
    }

    /**
     * @param  array  $group
     *
     * @return array
     */
    public function transform(array $group): array
    {
        $first = reset($group['transactions']);
        return [
            'id'           => (string)$group['id'],
            'created_at'   => $first['created_at']->toAtomString(),
            'updated_at'   => $first['updated_at']->toAtomString(),
            'user'         => (string)$first['user_id'],
            'group_title'  => $group['title'] ?? null,
            'transactions' => $this->transformTransactions($group['transactions'] ?? []),
            'links'        => [
                [
                    'rel' => 'self',
                    'uri' => sprintf('/transactions/%d', $group['id']),
                ],
            ],
        ];
    }

    /**
     * @param  array  $transactions
     * @return array
     */
    private function transformTransactions(array $transactions): array
    {
        $return = [];
        /** @var array $transaction */
        foreach ($transactions as $transaction) {
            $return[] = $this->transformTransaction($transaction);
        }
        return $return;
    }

    private function transformTransaction(array $transaction): array
    {
        $transaction         = new NullArrayObject($transaction);
        $journalId           = (int)$transaction['transaction_journal_id'];
        $meta                = new NullArrayObject($this->meta[$journalId] ?? []);
        $type                = $this->stringFromArray($transaction, 'transaction_type_type', TransactionType::WITHDRAWAL);
        $amount              = app('steam')->positive((string)($transaction['amount'] ?? '0'));
        $foreignAmount       = null;
        $nativeForeignAmount = null;
        if (null !== $transaction['foreign_amount']) {
            $foreignAmount       = app('steam')->positive($transaction['foreign_amount']);
            $nativeForeignAmount = $foreignAmount;
            if ($transaction['foreign_currency_id'] !== $this->default->id) {
                $rate                = $this->getRate($this->currencies[$transaction['foreign_currency_id']], $this->default, $transaction['date']);
                $nativeForeignAmount = bcmul($foreignAmount, $rate);
            }
        }

        $nativeAmount = $amount;
        if ($transaction['currency_id'] !== $this->default->id) {
            $rate         = $this->getRate($this->currencies[$transaction['currency_id']], $this->default, $transaction['date']);
            $nativeAmount = bcmul($amount, $rate);
        }

        return [
            'user'                           => (string)$transaction['user_id'],
            'transaction_journal_id'         => (string)$transaction['transaction_journal_id'],
            'type'                           => strtolower($type),
            'date'                           => $transaction['date']->toAtomString(),
            'order'                          => $transaction['order'],
            'currency_id'                    => (string)$transaction['currency_id'],
            'currency_code'                  => $transaction['currency_code'],
            'currency_name'                  => $transaction['currency_name'],
            'currency_symbol'                => $transaction['currency_symbol'],
            'currency_decimal_places'        => (int)$transaction['currency_decimal_places'],

            // converted to native currency
            'native_currency_converted'      => $transaction['currency_id'] !== $this->default->id,
            'native_currency_id'             => (string)$this->default->id,
            'native_currency_code'           => $this->default->code,
            'native_currency_name'           => $this->default->name,
            'native_currency_symbol'         => $this->default->symbol,
            'native_currency_decimal_places' => (int)$this->default->decimal_places,

            'foreign_currency_id'             => $this->stringFromArray($transaction, 'foreign_currency_id', null),
            'foreign_currency_code'           => $transaction['foreign_currency_code'],
            'foreign_currency_name'           => $transaction['foreign_currency_name'],
            'foreign_currency_symbol'         => $transaction['foreign_currency_symbol'],
            'foreign_currency_decimal_places' => $transaction['foreign_currency_decimal_places'],

            // foreign converted to native currency:
            'foreign_currency_converted'      => null !== $transaction['foreign_currency_id'] && $transaction['foreign_currency_id'] !== $this->default->id,

            'amount'                => $amount,
            'native_amount'         => $nativeAmount,
            'foreign_amount'        => $foreignAmount,
            'native_foreign_amount' => $nativeForeignAmount,
            'description'           => $transaction['description'],
            'source_id'             => (string)$transaction['source_account_id'],
            'source_name'           => $transaction['source_account_name'],
            'source_iban'           => $transaction['source_account_iban'],
            'source_type'           => $transaction['source_account_type'],
            'destination_id'        => (string)$transaction['destination_account_id'],
            'destination_name'      => $transaction['destination_account_name'],
            'destination_iban'      => $transaction['destination_account_iban'],
            'destination_type'      => $transaction['destination_account_type'],
            'budget_id'             => $this->stringFromArray($transaction, 'budget_id', null),
            'budget_name'           => $transaction['budget_name'],
            'category_id'           => $this->stringFromArray($transaction, 'category_id', null),
            'category_name'         => $transaction['category_name'],
            'bill_id'               => $this->stringFromArray($transaction, 'bill_id', null),
            'bill_name'             => $transaction['bill_name'],
            'reconciled'            => $transaction['reconciled'],

            //'notes'      => $this->groupRepos->getNoteText((int) $row['transaction_journal_id']),
            //'tags'       => $this->groupRepos->getTags((int) $row['transaction_journal_id']),

            'internal_reference' => $meta['internal_reference'],
            'external_id'        => $meta['external_id'],
            'original_source'    => $meta['original_source'],
            'recurrence_id'      => $meta['recurrence_id'],
            'recurrence_total'   => $meta['recurrence_total'],
            'recurrence_count'   => $meta['recurrence_count'],
            'bunq_payment_id'    => $meta['bunq_payment_id'],
            'external_url'       => $meta['external_url'],
            'import_hash_v2'     => $meta['import_hash_v2'],
            'sepa_cc'            => $meta['sepa_cc'],
            'sepa_ct_op'         => $meta['sepa_ct_op'],
            'sepa_ct_id'         => $meta['sepa_ct_id'],
            'sepa_db'            => $meta['sepa_db'],
            'sepa_country'       => $meta['sepa_country'],
            'sepa_ep'            => $meta['sepa_ep'],
            'sepa_ci'            => $meta['sepa_ci'],
            'sepa_batch_id'      => $meta['sepa_batch_id'],

            'interest_date' => $this->date($meta['interest_date']),
            'book_date'     => $this->date($meta['book_date']),
            'process_date'  => $this->date($meta['process_date']),
            'due_date'      => $this->date($meta['due_date']),
            'payment_date'  => $this->date($meta['payment_date']),
            'invoice_date'  => $this->date($meta['invoice_date']),

            // location data
            //            'longitude'     => $longitude,
            //            'latitude'      => $latitude,
            //            'zoom_level'    => $zoomLevel,
            //
            //            'has_attachments' => $this->hasAttachments((int) $row['transaction_journal_id']),
        ];
    }

    /**
     * TODO also in the old transformer.
     *
     * @param  NullArrayObject  $array
     * @param  string  $key
     * @param  string|null  $default
     *
     * @return string|null
     */
    private function stringFromArray(NullArrayObject $array, string $key, ?string $default): ?string
    {
        //Log::debug(sprintf('%s: %s', $key, var_export($array[$key], true)));
        if (null === $array[$key] && null === $default) {
            return null;
        }
        if (0 === $array[$key]) {
            return $default;
        }
        if ('0' === $array[$key]) {
            return $default;
        }
        if (null !== $array[$key]) {
            return (string)$array[$key];
        }

        if (null !== $default) {
            return $default;
        }

        return null;
    }

    /**
     * @param  string|null  $string
     * @return Carbon|null
     */
    private function date(?string $string): ?Carbon
    {
        if (null === $string) {
            return null;
        }
        Log::debug(sprintf('Now in date("%s")', $string));
        if (10 === strlen($string)) {
            return Carbon::createFromFormat('Y-m-d', $string, config('app.timezone'));
        }
        return Carbon::createFromFormat('Y-m-d H:i:s', $string, config('app.timezone'));
    }
}
