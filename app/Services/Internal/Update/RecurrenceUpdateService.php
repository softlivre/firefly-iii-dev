<?php
/**
 * RecurrenceUpdateService.php
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

namespace FireflyIII\Services\Internal\Update;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\TransactionCurrencyFactory;
use FireflyIII\Models\Note;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\RecurrenceRepetition;
use FireflyIII\Models\RecurrenceTransaction;
use FireflyIII\Services\Internal\Support\RecurringTransactionTrait;
use FireflyIII\Services\Internal\Support\TransactionTypeTrait;
use FireflyIII\User;
use JsonException;
use Illuminate\Support\Facades\Log;

/**
 * Class RecurrenceUpdateService
 *

 */
class RecurrenceUpdateService
{
    use TransactionTypeTrait;
    use RecurringTransactionTrait;

    private User $user;

    /**
     * Updates a recurrence.
     *
     * TODO if the user updates the type, the accounts must be validated again.
     *
     * @param  Recurrence  $recurrence
     * @param  array  $data
     *
     * @return Recurrence
     * @throws FireflyException
     */
    public function update(Recurrence $recurrence, array $data): Recurrence
    {
        $this->user = $recurrence->user;
        // update basic fields first:

        if (array_key_exists('recurrence', $data)) {
            $info = $data['recurrence'];
            if (array_key_exists('title', $info)) {
                $recurrence->title = $info['title'];
            }
            if (array_key_exists('description', $info)) {
                $recurrence->description = $info['description'];
            }
            if (array_key_exists('first_date', $info)) {
                $recurrence->first_date = $info['first_date'];
            }
            if (array_key_exists('repeat_until', $info)) {
                $recurrence->repeat_until = $info['repeat_until'];
                $recurrence->repetitions  = 0;
            }
            if (array_key_exists('nr_of_repetitions', $info)) {
                if (0 !== (int)$info['nr_of_repetitions']) {
                    $recurrence->repeat_until = null;
                }
                $recurrence->repetitions = $info['nr_of_repetitions'];
            }
            if (array_key_exists('apply_rules', $info)) {
                $recurrence->apply_rules = $info['apply_rules'];
            }
            if (array_key_exists('active', $info)) {
                $recurrence->active = $info['active'];
            }
            // update all meta data:
            if (array_key_exists('notes', $info)) {
                $this->setNoteText($recurrence, $info['notes']);
            }
        }
        $recurrence->save();

        // update all repetitions
        if (array_key_exists('repetitions', $data)) {
            Log::debug('Will update repetitions array');
            // update each repetition or throw error yay
            $this->updateRepetitions($recurrence, $data['repetitions'] ?? []);
        }
        // update all transactions:
        // update all transactions (and associated meta-data)
        if (array_key_exists('transactions', $data)) {
            $this->updateTransactions($recurrence, $data['transactions'] ?? []);
            //            $this->deleteTransactions($recurrence);
            //            $this->createTransactions($recurrence, $data['transactions'] ?? []);
        }

        return $recurrence;
    }

    /**
     * @param  Recurrence  $recurrence
     * @param  string  $text
     */
    private function setNoteText(Recurrence $recurrence, string $text): void
    {
        $dbNote = $recurrence->notes()->first();
        if ('' !== $text) {
            if (null === $dbNote) {
                $dbNote = new Note();
                $dbNote->noteable()->associate($recurrence);
            }
            $dbNote->text = trim($text);
            $dbNote->save();

            return;
        }
        $dbNote?->delete();
    }

    /**
     *
     * @param  Recurrence  $recurrence
     * @param  array  $repetitions
     *
     * @throws FireflyException
     */
    private function updateRepetitions(Recurrence $recurrence, array $repetitions): void
    {
        $originalCount = $recurrence->recurrenceRepetitions()->count();
        if (0 === count($repetitions)) {
            // wont drop repetition, rather avoid.
            return;
        }
        // user added or removed repetitions, delete all and recreate:
        if ($originalCount !== count($repetitions)) {
            Log::debug('Delete existing repetitions and create new ones.');
            $this->deleteRepetitions($recurrence);
            $this->createRepetitions($recurrence, $repetitions);

            return;
        }
        // loop all and try to match them:
        Log::debug('Loop and find');
        foreach ($repetitions as $current) {
            $match = $this->matchRepetition($recurrence, $current);
            if (null === $match) {
                throw new FireflyException('Cannot match recurring repetition to existing repetition. Not sure what to do. Break.');
            }
            $fields = [
                'type'    => 'repetition_type',
                'moment'  => 'repetition_moment',
                'skip'    => 'repetition_skip',
                'weekend' => 'weekend',
            ];
            foreach ($fields as $field => $column) {
                if (array_key_exists($field, $current)) {
                    $match->$column = $current[$field];
                    $match->save();
                }
            }
        }
    }

    /**
     * @param  Recurrence  $recurrence
     * @param  array  $data
     *
     * @return RecurrenceRepetition|null
     */
    private function matchRepetition(Recurrence $recurrence, array $data): ?RecurrenceRepetition
    {
        $originalCount = $recurrence->recurrenceRepetitions()->count();
        if (1 === $originalCount) {
            Log::debug('Return the first one');
            /** @var RecurrenceRepetition $result */
            $result = $recurrence->recurrenceRepetitions()->first();
            return $result;
        }
        // find it:
        $fields = [
            'id'      => 'id',
            'type'    => 'repetition_type',
            'moment'  => 'repetition_moment',
            'skip'    => 'repetition_skip',
            'weekend' => 'weekend',
        ];
        $query  = $recurrence->recurrenceRepetitions();
        foreach ($fields as $field => $column) {
            if (array_key_exists($field, $data)) {
                $query->where($column, $data[$field]);
            }
        }
        /** @var RecurrenceRepetition|null */
        return $query->first();
    }

    /**
     * TODO this method is very complex.
     *
     * @param  Recurrence  $recurrence
     * @param  array  $transactions
     *
     * @throws FireflyException
     * @throws JsonException
     */
    private function updateTransactions(Recurrence $recurrence, array $transactions): void
    {
        Log::debug('Now in updateTransactions()');
        $originalCount = $recurrence->recurrenceTransactions()->count();
        if (0 === count($transactions)) {
            // won't drop transactions, rather avoid.
            return;
        }
        // user added or removed repetitions, delete all and recreate:
        if ($originalCount !== count($transactions)) {
            Log::debug('Delete existing transactions and create new ones.');
            $this->deleteTransactions($recurrence);
            $this->createTransactions($recurrence, $transactions);

            return;
        }
        $currencyFactory = app(TransactionCurrencyFactory::class);
        // loop all and try to match them:
        Log::debug(sprintf('Count is equal (%d), update transactions.', $originalCount));
        foreach ($transactions as $current) {
            $match = $this->matchTransaction($recurrence, $current);
            if (null === $match) {
                throw new FireflyException('Cannot match recurring transaction to existing transaction. Not sure what to do. Break.');
            }
            // complex loop to find currency:
            $currency        = null;
            $foreignCurrency = null;
            if (array_key_exists('currency_id', $current) || array_key_exists('currency_code', $current)) {
                $currency = $currencyFactory->find($current['currency_id'] ?? null, $currency['currency_code'] ?? null);
            }
            if (null === $currency) {
                unset($current['currency_id'], $current['currency_code']);
            }
            if (null !== $currency) {
                $current['currency_id'] = (int)$currency->id;
            }
            if (array_key_exists('foreign_currency_id', $current) || array_key_exists('foreign_currency_code', $current)) {
                $foreignCurrency = $currencyFactory->find($current['foreign_currency_id'] ?? null, $currency['foreign_currency_code'] ?? null);
            }
            if (null === $foreignCurrency) {
                unset($current['foreign_currency_id'], $currency['foreign_currency_code']);
            }
            if (null !== $foreignCurrency) {
                $current['foreign_currency_id'] = (int)$foreignCurrency->id;
            }

            // update fields that are part of the recurring transaction itself.
            $fields = [
                'source_id'           => 'source_id',
                'destination_id'      => 'destination_id',
                'amount'              => 'amount',
                'foreign_amount'      => 'foreign_amount',
                'description'         => 'description',
                'currency_id'         => 'transaction_currency_id',
                'foreign_currency_id' => 'foreign_currency_id',
            ];
            foreach ($fields as $field => $column) {
                if (array_key_exists($field, $current)) {
                    $match->$column = $current[$field];
                    $match->save();
                }
            }
            // update meta data
            if (array_key_exists('budget_id', $current)) {
                $this->setBudget($match, (int)$current['budget_id']);
            }
            if (array_key_exists('bill_id', $current)) {
                $this->setBill($match, (int)$current['bill_id']);
            }
            // reset category if name is set but empty:
            // can be removed when v1 is retired.
            if (array_key_exists('category_name', $current) && '' === (string)$current['category_name']) {
                Log::debug('Category name is submitted but is empty. Set category to be empty.');
                $current['category_name'] = null;
                $current['category_id']   = 0;
            }

            if (array_key_exists('category_id', $current)) {
                Log::debug(sprintf('Category ID is submitted, set category to be %d.', (int)$current['category_id']));
                $this->setCategory($match, (int)$current['category_id']);
            }

            if (array_key_exists('tags', $current) && is_array($current['tags'])) {
                $this->updateTags($match, $current['tags']);
            }
            if (array_key_exists('piggy_bank_id', $current)) {
                $this->updatePiggyBank($match, (int)$current['piggy_bank_id']);
            }
        }
    }

    /**
     * @param  Recurrence  $recurrence
     * @param  array  $data
     *
     * @return RecurrenceTransaction|null
     */
    private function matchTransaction(Recurrence $recurrence, array $data): ?RecurrenceTransaction
    {
        Log::debug('Now in matchTransaction()');
        $originalCount = $recurrence->recurrenceTransactions()->count();
        if (1 === $originalCount) {
            Log::debug('Return the first one.');
            /** @var RecurrenceTransaction|null */
            return $recurrence->recurrenceTransactions()->first();
        }
        // find it based on data
        $fields = [
            'id'                  => 'id',
            'currency_id'         => 'transaction_currency_id',
            'foreign_currency_id' => 'foreign_currency_id',
            'source_id'           => 'source_id',
            'destination_id'      => 'destination_id',
            'amount'              => 'amount',
            'foreign_amount'      => 'foreign_amount',
            'description'         => 'description',
        ];
        $query  = $recurrence->recurrenceTransactions();
        foreach ($fields as $field => $column) {
            if (array_key_exists($field, $data)) {
                $query->where($column, $data[$field]);
            }
        }
        /** @var RecurrenceTransaction|null */
        return $query->first();
    }
}
