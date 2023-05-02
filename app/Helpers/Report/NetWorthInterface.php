<?php
/**
 * NetWorthInterface.php
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

namespace FireflyIII\Helpers\Report;

use Carbon\Carbon;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Interface NetWorthInterface
 *
 */
interface NetWorthInterface
{
    /**
     * TODO unsure why this is deprecated.
     *
     * Returns the user's net worth in an array with the following layout:
     *
     * -
     *  - currency: TransactionCurrency object
     *  - date: the current date
     *  - amount: the user's net worth in that currency.
     *
     * This repeats for each currency the user has transactions in.
     * Result of this method is cached.
     *
     * @param  Collection  $accounts
     * @param  Carbon  $date
     * @return array
     * @deprecated
     */
    public function getNetWorthByCurrency(Collection $accounts, Carbon $date): array;

    /**
     * @param  User|Authenticatable|null  $user
     */
    public function setUser(User|Authenticatable|null $user): void;

    /**
     * TODO move to repository
     *
     * Same as above but cleaner function with less dependencies.
     *
     * @param  Carbon  $date
     *
     * @return array
     */
    public function sumNetWorthByCurrency(Carbon $date): array;
}
