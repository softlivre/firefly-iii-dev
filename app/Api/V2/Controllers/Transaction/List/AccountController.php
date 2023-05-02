<?php

/*
 * AccountController.php
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

namespace FireflyIII\Api\V2\Controllers\Transaction\List;

use FireflyIII\Api\V2\Controllers\Controller;
use FireflyIII\Api\V2\Request\Transaction\ListRequest;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Account;
use FireflyIII\Support\Http\Api\TransactionFilter;
use FireflyIII\Transformers\V2\TransactionGroupTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Class AccountController
 */
class AccountController extends Controller
{
    use TransactionFilter;

    /**
     * This endpoint is documented at:
     * https://api-docs.firefly-iii.org/?urls.primaryName=2.0.0%20(v2)#/accounts/listTransactionByAccount
     *
     * @param  ListRequest  $request
     * @param  Account  $account
     * @return JsonResponse
     */
    public function listTransactions(ListRequest $request, Account $account): JsonResponse
    {
        // collect transactions:
        $type  = $request->get('type') ?? 'default';
        $limit = (int)$request->get('limit');
        $page  = (int)$request->get('page');
        $page  = max($page, 1);

        if ($limit > 0 && $limit <= $this->pageSize) {
            $this->pageSize = $limit;
        }

        $types = $this->mapTransactionTypes($type);

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setAccounts(new Collection([$account]))
                  ->withAPIInformation()
                  ->setLimit($this->pageSize)
                  ->setPage($page)
                  ->setTypes($types);

        // TODO date filter
        //if (null !== $this->parameters->get('start') && null !== $this->parameters->get('end')) {
        //    $collector->setRange($this->parameters->get('start'), $this->parameters->get('end'));
        //}

        $paginator = $collector->getPaginatedGroups();
        $paginator->setPath(route('api.v2.accounts.transactions', [$account->id])); // TODO  . $this->buildParams()

        return response()
            ->json($this->jsonApiList('transactions', $paginator, new TransactionGroupTransformer()))
            ->header('Content-Type', self::CONTENT_TYPE);
    }
}
