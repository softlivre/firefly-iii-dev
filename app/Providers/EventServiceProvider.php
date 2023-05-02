<?php

/**
 * EventServiceProvider.php
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

namespace FireflyIII\Providers;

use FireflyIII\Events\ActuallyLoggedIn;
use FireflyIII\Events\Admin\InvitationCreated;
use FireflyIII\Events\AdminRequestedTestMessage;
use FireflyIII\Events\ChangedPiggyBankAmount;
use FireflyIII\Events\DestroyedTransactionGroup;
use FireflyIII\Events\DetectedNewIPAddress;
use FireflyIII\Events\NewVersionAvailable;
use FireflyIII\Events\RegisteredUser;
use FireflyIII\Events\RequestedNewPassword;
use FireflyIII\Events\RequestedReportOnJournals;
use FireflyIII\Events\RequestedSendWebhookMessages;
use FireflyIII\Events\RequestedVersionCheckStatus;
use FireflyIII\Events\StoredAccount;
use FireflyIII\Events\StoredTransactionGroup;
use FireflyIII\Events\TriggeredAuditLog;
use FireflyIII\Events\UpdatedAccount;
use FireflyIII\Events\UpdatedTransactionGroup;
use FireflyIII\Events\UserChangedEmail;
use FireflyIII\Events\WarnUserAboutBill;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\PiggyBankRepetition;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Illuminate\Support\Facades\Log;

/**
 * Class EventServiceProvider.
 *

 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen
        = [
            // is a User related event.
            RegisteredUser::class               => [
                'FireflyIII\Handlers\Events\UserEventHandler@sendRegistrationMail',
                'FireflyIII\Handlers\Events\UserEventHandler@sendAdminRegistrationNotification',
                'FireflyIII\Handlers\Events\UserEventHandler@attachUserRole',
                'FireflyIII\Handlers\Events\UserEventHandler@createGroupMembership',
                'FireflyIII\Handlers\Events\UserEventHandler@createExchangeRates',
            ],
            // is a User related event.
            Login::class                        => [
                'FireflyIII\Handlers\Events\UserEventHandler@checkSingleUserIsAdmin',
                'FireflyIII\Handlers\Events\UserEventHandler@demoUserBackToEnglish',
            ],
            ActuallyLoggedIn::class             => [
                'FireflyIII\Handlers\Events\UserEventHandler@storeUserIPAddress',
            ],
            DetectedNewIPAddress::class         => [
                'FireflyIII\Handlers\Events\UserEventHandler@notifyNewIPAddress',
            ],
            RequestedVersionCheckStatus::class  => [
                'FireflyIII\Handlers\Events\VersionCheckEventHandler@checkForUpdates',
            ],
            RequestedReportOnJournals::class    => [
                'FireflyIII\Handlers\Events\AutomationHandler@reportJournals',
            ],

            // is a User related event.
            RequestedNewPassword::class         => [
                'FireflyIII\Handlers\Events\UserEventHandler@sendNewPassword',
            ],
            // is a User related event.
            UserChangedEmail::class             => [
                'FireflyIII\Handlers\Events\UserEventHandler@sendEmailChangeConfirmMail',
                'FireflyIII\Handlers\Events\UserEventHandler@sendEmailChangeUndoMail',
            ],
            // admin related
            AdminRequestedTestMessage::class    => [
                'FireflyIII\Handlers\Events\AdminEventHandler@sendTestMessage',
            ],
            NewVersionAvailable::class          => [
                'FireflyIII\Handlers\Events\AdminEventHandler@sendNewVersion',
            ],
            InvitationCreated::class            => [
                'FireflyIII\Handlers\Events\AdminEventHandler@sendInvitationNotification',
                'FireflyIII\Handlers\Events\UserEventHandler@sendRegistrationInvite',
            ],

            // is a Transaction Journal related event.
            StoredTransactionGroup::class       => [
                'FireflyIII\Handlers\Events\StoredGroupEventHandler@processRules',
                'FireflyIII\Handlers\Events\StoredGroupEventHandler@recalculateCredit',
                'FireflyIII\Handlers\Events\StoredGroupEventHandler@triggerWebhooks',
            ],
            // is a Transaction Journal related event.
            UpdatedTransactionGroup::class      => [
                'FireflyIII\Handlers\Events\UpdatedGroupEventHandler@unifyAccounts',
                'FireflyIII\Handlers\Events\UpdatedGroupEventHandler@processRules',
                'FireflyIII\Handlers\Events\UpdatedGroupEventHandler@recalculateCredit',
                'FireflyIII\Handlers\Events\UpdatedGroupEventHandler@triggerWebhooks',
            ],
            DestroyedTransactionGroup::class    => [
                'FireflyIII\Handlers\Events\DestroyedGroupEventHandler@triggerWebhooks',
            ],
            // API related events:
            AccessTokenCreated::class           => [
                'FireflyIII\Handlers\Events\APIEventHandler@accessTokenCreated',
            ],

            // Webhook related event:
            RequestedSendWebhookMessages::class => [
                'FireflyIII\Handlers\Events\WebhookEventHandler@sendWebhookMessages',
            ],

            // account related events:
            StoredAccount::class                => [
                'FireflyIII\Handlers\Events\StoredAccountEventHandler@recalculateCredit',
            ],
            UpdatedAccount::class               => [
                'FireflyIII\Handlers\Events\UpdatedAccountEventHandler@recalculateCredit',
            ],

            // bill related events:
            WarnUserAboutBill::class            => [
                'FireflyIII\Handlers\Events\BillEventHandler@warnAboutBill',
            ],

            // audit log events:
            TriggeredAuditLog::class            => [
                'FireflyIII\Handlers\Events\AuditEventHandler@storeAuditEvent',
            ],
            // piggy bank related events:
            ChangedPiggyBankAmount::class       => [
                'FireflyIII\Handlers\Events\PiggyBankEventHandler@changePiggyAmount',
            ],
        ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
        $this->registerCreateEvents();
        $this->registerBudgetEvents();
    }

    /**
     * TODO needs a dedicated (static) method.
     */
    protected function registerCreateEvents(): void
    {
        PiggyBank::created(
            static function (PiggyBank $piggyBank) {
                $repetition = new PiggyBankRepetition();
                $repetition->piggyBank()->associate($piggyBank);
                $repetition->startdate     = $piggyBank->startdate;
                $repetition->targetdate    = $piggyBank->targetdate;
                $repetition->currentamount = 0;
                $repetition->save();
            }
        );
    }

    /**
     * TODO needs a dedicated method.
     */
    protected function registerBudgetEvents(): void
    {
        $func = static function (BudgetLimit $limit) {
            Log::debug('Trigger budget limit event.');
            // find available budget with same period and same currency or create it.
            // then set it or add money:
            $user            = $limit->budget->user;
            $availableBudget = $user
                ->availableBudgets()
                ->where('start_date', $limit->start_date->format('Y-m-d'))
                ->where('end_date', $limit->end_date->format('Y-m-d'))
                ->where('transaction_currency_id', $limit->transaction_currency_id)
                ->first();
            // update!
            if (null !== $availableBudget) {
                $repository = app(BudgetLimitRepositoryInterface::class);
                $repository->setUser($user);
                $set = $repository->getAllBudgetLimitsByCurrency($limit->transactionCurrency, $limit->start_date, $limit->end_date);
                $sum = (string)$set->sum('amount');


                Log::debug(
                    sprintf(
                        'Because budget limit #%d had its amount changed to %s, available budget limit #%d will be updated.',
                        $limit->id,
                        $limit->amount,
                        $availableBudget->id
                    )
                );
                $availableBudget->amount = $sum;
                $availableBudget->save();
                return;
            }
            Log::debug('Does not exist, create it.');
            // create it.
            $data       = [
                'amount'      => $limit->amount,
                'start'       => $limit->start_date,
                'end'         => $limit->end_date,
                'currency_id' => $limit->transaction_currency_id,
            ];
            $repository = app(AvailableBudgetRepositoryInterface::class);
            $repository->setUser($user);
            $repository->store($data);
        };

        BudgetLimit::created($func);
        BudgetLimit::updated($func);
    }
}
