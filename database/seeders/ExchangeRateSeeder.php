<?php

/*
 * ExchangeRateSeeder.php
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

namespace Database\Seeders;

use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Log;

/**
 * Class ExchangeRateSeeder
 */
class ExchangeRateSeeder extends Seeder
{
    private Collection $users;

    /**
     * @return void
     */
    public function run(): void
    {
        $count = User::count();
        if (0 === $count) {
            Log::debug('Will not seed exchange rates yet.');
            return;
        }
        $users  = User::get();
        $date   = config('cer.date');
        $rates  = config('cer.rates');
        $usable = [];
        foreach ($rates as $rate) {
            $from = $this->getCurrency($rate[0]);
            $to   = $this->getCurrency($rate[1]);
            if (null !== $from && null !== $to) {
                $usable[] = [$from, $to, $rate[2]];
            }
        }
        unset($rates, $from, $to, $rate);

        /** @var User $user */
        foreach ($users as $user) {
            foreach ($usable as $rate) {
                if (!$this->hasRate($user, $rate[0], $rate[1], $date)) {
                    $this->addRate($user, $rate[0], $rate[1], $date, $rate[2]);
                }
            }
        }
    }

    /**
     * @param  string  $code
     * @return TransactionCurrency|null
     */
    private function getCurrency(string $code): ?TransactionCurrency
    {
        return TransactionCurrency::whereNull('deleted_at')->where('code', $code)->first();
    }

    /**
     * @param  User  $user
     * @param  TransactionCurrency  $from
     * @param  TransactionCurrency  $to
     * @param  string  $date
     * @return bool
     */
    private function hasRate(User $user, TransactionCurrency $from, TransactionCurrency $to, string $date): bool
    {
        return $user->currencyExchangeRates()
                    ->where('from_currency_id', $from->id)
                    ->where('to_currency_id', $to->id)
                    ->where('date', $date)
                    ->count() > 0;
    }

    /**
     * @param  User  $user
     * @param  TransactionCurrency  $from
     * @param  TransactionCurrency  $to
     * @param  string  $date
     * @param  float  $rate
     * @return void
     */
    private function addRate(User $user, TransactionCurrency $from, TransactionCurrency $to, string $date, float $rate): void
    {
        /** @var User $user */
        CurrencyExchangeRate::create(
            [
                'user_id'          => $user->id,
                'from_currency_id' => $from->id,
                'to_currency_id'   => $to->id,
                'date'             => $date,
                'rate'             => $rate,
            ]
        );
    }
}
