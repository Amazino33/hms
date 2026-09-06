<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BacksUpDatabase;
use App\Models\StaffDebt;
use App\Models\StockTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A narrower reset than app:clear-test-data: only staff debts and
 * storekeeper stock transfers — for clearing test-period accountability
 * records and a duplicate-transfer incident's history without touching
 * orders, shifts, count sessions, stock adjustments, or any transaction
 * log.
 *
 * Deliberately does NOT touch InventoryItem/IngredientInventoryItem
 * (current stock quantities) or InventoryTransaction/IngredientTransaction
 * (the movement ledger) — correcting whatever stock level the duplicate
 * transfer left wrong is a separate, deliberate decision, done by hand.
 */
class ClearDebtsAndTransfersCommand extends Command
{
    use BacksUpDatabase;

    protected $signature = 'app:clear-debts-and-transfers {--force : Skip the confirmation prompt}';

    protected $description = 'Clear all staff debts and all storekeeper stock-transfer history — leaves stock quantities, the transaction ledger, orders, shifts, and count sessions untouched';

    public function handle(): int
    {
        $counts = [
            'Staff debts (and repayments)' => StaffDebt::count(),
            'Stock transfers (and their product/ingredient items + discrepancies)' => StockTransfer::count(),
        ];

        $this->warn('This will permanently delete:');
        foreach ($counts as $label => $count) {
            $this->line("  - {$label}: {$count}");
        }
        $this->newLine();
        $this->line('It will NOT touch current stock quantities, the inventory/ingredient transaction ledger, orders, shifts, count sessions, or stock adjustments.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Cancelled — nothing was deleted.');

            return self::SUCCESS;
        }

        if (! app()->environment('testing')) {
            $backupFile = $this->backupDatabase('pre_debts_transfers_reset');
            $this->info("Backup saved to {$backupFile}");
            $this->newLine();
        }

        DB::transaction(function () {
            // Staff debts cascade-delete their own repayments.
            StaffDebt::query()->delete();

            // Stock transfers cascade-delete their own transfer items (both
            // the product and ingredient variants), which in turn
            // cascade-delete any transfer discrepancies tied to those items.
            StockTransfer::query()->delete();
        });

        $this->info('Done — staff debts and stock-transfer history cleared. Stock quantities and the transaction ledger were left exactly as they were.');

        return self::SUCCESS;
    }
}
