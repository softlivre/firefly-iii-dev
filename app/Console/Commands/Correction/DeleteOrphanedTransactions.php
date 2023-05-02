<?php
/**
 * DeleteOrphanedTransactions.php
 * Copyright (c) 2020 james@firefly-iii.org
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

namespace FireflyIII\Console\Commands\Correction;

use Exception;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Deletes transactions where the journal has been deleted.
 */
class DeleteOrphanedTransactions extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes orphaned transactions.';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firefly-iii:delete-orphaned-transactions';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        $start = microtime(true);
        $this->deleteOrphanedJournals();
        $this->deleteOrphanedTransactions();
        $this->deleteFromOrphanedAccounts();
        $end = round(microtime(true) - $start, 2);
        $this->info(sprintf('Verified orphans in %s seconds', $end));

        return 0;
    }

    private function deleteOrphanedJournals(): void
    {
        $set   = TransactionJournal::leftJoin('transaction_groups', 'transaction_journals.transaction_group_id', 'transaction_groups.id')
                                   ->whereNotNull('transaction_groups.deleted_at')
                                   ->whereNull('transaction_journals.deleted_at')
                                   ->get(['transaction_journals.id', 'transaction_journals.transaction_group_id']);
        $count = $set->count();
        if (0 === $count) {
            $this->info('No orphaned journals.');
        }
        if ($count > 0) {
            $this->info(sprintf('Found %d orphaned journal(s).', $count));
            foreach ($set as $entry) {
                $journal = TransactionJournal::withTrashed()->find((int)$entry->id);
                if (null !== $journal) {
                    $journal->delete();
                    $this->info(
                        sprintf(
                            'Journal #%d (part of deleted transaction group #%d) has been deleted as well.',
                            $entry->id,
                            $entry->transaction_group_id
                        )
                    );
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function deleteOrphanedTransactions(): void
    {
        $count = 0;
        $set   = Transaction::leftJoin('transaction_journals', 'transactions.transaction_journal_id', '=', 'transaction_journals.id')
                            ->whereNotNull('transaction_journals.deleted_at')
                            ->whereNull('transactions.deleted_at')
                            ->whereNotNull('transactions.id')
                            ->get(
                                [
                                    'transaction_journals.id as journal_id',
                                    'transactions.id as transaction_id',
                                ]
                            );
        /** @var stdClass $entry */
        foreach ($set as $entry) {
            $transaction = Transaction::find((int)$entry->transaction_id);
            if (null !== $transaction) {
                $transaction->delete();
                $this->info(
                    sprintf(
                        'Transaction #%d (part of deleted transaction journal #%d) has been deleted as well.',
                        $entry->transaction_id,
                        $entry->journal_id
                    )
                );
                ++$count;
            }
        }
        if (0 === $count) {
            $this->info('No orphaned transactions.');
        }
    }

    /**
     *
     */
    private function deleteFromOrphanedAccounts(): void
    {
        $set
               = Transaction::leftJoin('accounts', 'transactions.account_id', '=', 'accounts.id')
                            ->whereNotNull('accounts.deleted_at')
                            ->get(['transactions.*']);
        $count = 0;
        /** @var Transaction $transaction */
        foreach ($set as $transaction) {
            // delete journals
            $journal = TransactionJournal::find((int)$transaction->transaction_journal_id);
            if ($journal) {
                $journal->delete();
            }
            Transaction::where('transaction_journal_id', (int)$transaction->transaction_journal_id)->delete();
            $this->line(
                sprintf(
                    'Deleted transaction journal #%d because account #%d was already deleted.',
                    $transaction->transaction_journal_id,
                    $transaction->account_id
                )
            );
            $count++;
        }
        if (0 === $count) {
            $this->info('No orphaned accounts.');
        }
    }
}
