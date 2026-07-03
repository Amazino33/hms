<?php

namespace Database\Seeders;

use App\Models\PagePermission;
use Illuminate\Database\Seeder;

class PagePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // POS Page - Waiters and Super Admin
            [
                'page_class' => 'App\Filament\Pages\PosPage',
                'page_name' => 'POS Page',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\PosPage',
                'page_name' => 'POS Page',
                'role_name' => 'waiter',
            ],
            [
                'page_class' => 'App\Filament\Pages\PosPage',
                'page_name' => 'POS Page',
                'role_name' => 'porter',
            ],

            // Floor Plan - Managers, Waiters, Super Admin
            [
                'page_class' => 'App\Filament\Pages\FloorPlan',
                'page_name' => 'Floor Plan',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\FloorPlan',
                'page_name' => 'Floor Plan',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\FloorPlan',
                'page_name' => 'Floor Plan',
                'role_name' => 'waiter',
            ],
            [
                'page_class' => 'App\Filament\Pages\FloorPlan',
                'page_name' => 'Floor Plan',
                'role_name' => 'porter',
            ],

            // Kitchen Display - Chefs and Super Admin
            [
                'page_class' => 'App\Filament\Pages\KitchenDisplay',
                'page_name' => 'Kitchen Display',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\KitchenDisplay',
                'page_name' => 'Kitchen Display',
                'role_name' => 'chef',
            ],

            // Bar Display - Bartenders, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\BarDisplay',
                'page_name' => 'Bar Display',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\BarDisplay',
                'page_name' => 'Bar Display',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\BarDisplay',
                'page_name' => 'Bar Display',
                'role_name' => 'bartender',
            ],

            // My History - All staff roles
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'waiter',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'porter',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'chef',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHistory',
                'page_name' => 'My History',
                'role_name' => 'bartender',
            ],

            // Quick Inventory Update - Storekeepers and Super Admin
            [
                'page_class' => 'App\Filament\Pages\QuickInventoryUpdate',
                'page_name' => 'Quick Inventory Update',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\QuickInventoryUpdate',
                'page_name' => 'Quick Inventory Update',
                'role_name' => 'storekeeper',
            ],

            // Receive Transfers - Multiple roles
            [
                'page_class' => 'App\Filament\Pages\ReceiveTransfers',
                'page_name' => 'Receive Transfers',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReceiveTransfers',
                'page_name' => 'Receive Transfers',
                'role_name' => 'chef',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReceiveTransfers',
                'page_name' => 'Receive Transfers',
                'role_name' => 'bartender',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReceiveTransfers',
                'page_name' => 'Receive Transfers',
                'role_name' => 'storekeeper',
            ],

            // Storekeeper Transfers - Storekeepers and Super Admin
            [
                'page_class' => 'App\Filament\Pages\StorekeeperTransfers',
                'page_name' => 'Storekeeper Transfers',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\StorekeeperTransfers',
                'page_name' => 'Storekeeper Transfers',
                'role_name' => 'storekeeper',
            ],

            // Stock Valuation - Only Super Admin
            [
                'page_class' => 'App\Filament\Pages\StockValuation',
                'page_name' => 'Stock Valuation',
                'role_name' => 'super_admin',
            ],

            // My Shift Report - All staff (not admin)
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'waiter',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'chef',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'bartender',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'storekeeper',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyShiftReport',
                'page_name' => 'My Shift Report',
                'role_name' => 'porter',
            ],

            // Waiter Ledger - Managers, Admins, Super Admin only
            [
                'page_class' => 'App\Filament\Pages\WaiterLedger',
                'page_name' => 'Waiter Ledger',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\WaiterLedger',
                'page_name' => 'Waiter Ledger',
                'role_name' => 'admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\WaiterLedger',
                'page_name' => 'Waiter Ledger',
                'role_name' => 'manager',
            ],
        ];

        foreach ($permissions as $permission) {
            PagePermission::firstOrCreate(
                ['page_class' => $permission['page_class'], 'role_name' => $permission['role_name']],
                $permission,
            );
        }

        $this->command->info('Page permissions created successfully!');
    }
}