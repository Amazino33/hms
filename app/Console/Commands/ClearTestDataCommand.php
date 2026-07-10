<?php

namespace App\Console\Commands;

use App\Models\CashDrop;
use App\Models\CountSession;
use App\Models\FridgeRestockMark;
use App\Models\IngredientTransaction;
use App\Models\InventoryTransaction;
use App\Models\KioskRegistrationCode;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * One-shot cleanup for starting fresh: clears every transactional/history
 * record (orders, payments, commissions, shifts, cash drops, staff debts,
 * count sessions, stock transfers, inventory/ingredient transaction logs,
 * stock adjustments, kiosk registration codes, fridge restock marks,
 * activity log, notifications, and active login sessions) and resets every
 * restaurant table to available. Deliberately never touches users,
 * products, current stock quantities, categories, menu items, warehouses,
 * roles/permissions, or guests/rooms/bookings — those are configuration
 * and real hotel data, not history.
 *
 * Takes its own mysqldump backup before deleting anything, mirroring
 * deploy.sh's pre-deploy backup step — this command isn't only ever run
 * right before a deploy, so it can't rely on that backup already existing.
 */
class ClearTestDataCommand extends Command
{
    protected $signature = 'app:clear-test-data {--force : Skip the confirmation prompt}';

    protected $description = 'Clear all transactional/history data (orders, shifts, counts, stock movements, logs, sessions) for a fresh start';

    public function handle(): int
    {
        $counts = [
            'Orders (and their items/payments/commissions/voids)' => Order::count(),
            'Shifts (and their cash drops)' => Shift::count(),
            'Staff debts (and repayments)' => StaffDebt::count(),
            'Count sessions (and their items)' => CountSession::count(),
            'Stock transfers (and their items)' => StockTransfer::count(),
            'Inventory transaction log entries' => InventoryTransaction::count(),
            'Ingredient transaction log entries' => IngredientTransaction::count(),
            'Stock adjustments' => StockAdjustment::count(),
            'Kiosk registration codes' => KioskRegistrationCode::count(),
            'Fridge restock marks' => FridgeRestockMark::count(),
            'Activity log entries' => Activity::count(),
            'Notifications' => DB::table('notifications')->count(),
            'Active login sessions (everyone gets logged out)' => DB::table('sessions')->count(),
        ];

        $this->warn('This will permanently delete:');
        foreach ($counts as $label => $count) {
            $this->line("  - {$label}: {$count}");
        }
        $this->newLine();
        $this->line('It will NOT touch users, products, current stock quantities, categories, menu items, warehouses, roles, or guests/rooms/bookings.');
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Are you sure you want to continue?')) {
            $this->info('Cancelled — nothing was deleted.');

            return self::SUCCESS;
        }

        // Tests run against an in-memory SQLite database, not the real
        // mysql connection this backs up — nothing meaningful to shell out
        // to mysqldump for there, and doing so would just break every test.
        if (!app()->environment('testing')) {
            $backupFile = $this->backupDatabase();
            $this->info("Backup saved to {$backupFile}");
            $this->newLine();
        }

        DB::transaction(function () {
            // Orders cascade-delete their own order_items, order_payments,
            // commissions, and unreturnable_voids.
            Order::query()->delete();

            // Staff debts cascade-delete their own repayments.
            StaffDebt::query()->delete();

            // Count sessions cascade-delete their own items and sub-counts.
            CountSession::query()->delete();

            // Stock transfers cascade-delete their own transfer items
            // (both the product and ingredient variants).
            StockTransfer::query()->delete();

            // Shifts cascade-delete their own cash_drops.
            Shift::query()->delete();

            InventoryTransaction::query()->delete();
            IngredientTransaction::query()->delete();
            StockAdjustment::query()->delete();
            KioskRegistrationCode::query()->delete();
            FridgeRestockMark::query()->delete();
            Activity::query()->delete();
            DB::table('notifications')->delete();

            Table::query()->update(['status' => 'available']);
        });

        // Deliberately outside the transaction and last: this immediately
        // logs out everyone with an active session (including kiosks
        // mid-shift), so it should only take effect once everything else
        // has committed successfully.
        DB::table('sessions')->delete();

        $this->info('Done — every table is now available for a fresh start.');

        return self::SUCCESS;
    }

    private function backupDatabase(): string
    {
        $connection = config('database.connections.mysql');

        $backupDir = storage_path('backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/pre_reset_' . now()->format('Ymd_His') . '.sql';

        $command = sprintf(
            'mysqldump -h %s -u %s -p%s %s > %s',
            escapeshellarg($connection['host']),
            escapeshellarg($connection['username']),
            escapeshellarg($connection['password']),
            escapeshellarg($connection['database']),
            escapeshellarg($backupFile),
        );

        exec($command, result_code: $exitCode);

        if ($exitCode !== 0 || !file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new \RuntimeException('Backup failed — aborting before any data was deleted. Check that mysqldump is installed and reachable.');
        }

        return $backupFile;
    }
}
