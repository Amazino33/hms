<?php

namespace App\Console\Commands;

use App\Models\CashDrop;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * One-shot cleanup for going live after a testing period: clears every
 * transactional record accumulated while testing the kiosk (orders,
 * payments, shifts, cash drops, staff debts, activity log) and resets
 * every table to available. Deliberately never touches users, products,
 * categories, menu items, roles/permissions, or kiosk device
 * registrations — those are real configuration, not test data.
 */
class ClearTestDataCommand extends Command
{
    protected $signature = 'app:clear-test-data {--force : Skip the confirmation prompt}';

    protected $description = 'Clear all orders, payments, shifts, cash drops, staff debts, and activity log entries, and reset every table to available';

    public function handle(): int
    {
        $counts = [
            'Orders (and their items/payments)' => Order::count(),
            'Shifts (and their cash drops)' => Shift::count(),
            'Staff debts (and repayments)' => StaffDebt::count(),
            'Activity log entries' => Activity::count(),
        ];

        $this->warn('This will permanently delete:');
        foreach ($counts as $label => $count) {
            $this->line("  - {$label}: {$count}");
        }
        $this->newLine();
        $this->line('It will NOT touch users, products, categories, menu items, roles, or kiosk device registrations.');
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Are you sure you want to continue?')) {
            $this->info('Cancelled — nothing was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            // Orders cascade-delete their own order_items and order_payments.
            Order::query()->delete();

            // Shifts cascade-delete their own cash_drops.
            Shift::query()->delete();

            // Staff debts cascade-delete their own repayments.
            StaffDebt::query()->delete();

            Activity::query()->delete();

            Table::query()->update(['status' => 'available']);
        });

        $this->info('Done — every table is now available for a fresh start.');

        return self::SUCCESS;
    }
}
