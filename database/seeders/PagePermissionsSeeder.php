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

            // Record Procurement (goods receipt) - Storekeepers and Super Admin
            [
                'page_class' => 'App\Filament\Pages\NewProcurement',
                'page_name' => 'Record Procurement',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\NewProcurement',
                'page_name' => 'Record Procurement',
                'role_name' => 'storekeeper',
            ],

            // Transfer Discrepancies - Managers, Admins, Super Admin
            [
                'page_class' => 'App\Filament\Pages\TransferDiscrepancies',
                'page_name' => 'Transfer Discrepancies',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\TransferDiscrepancies',
                'page_name' => 'Transfer Discrepancies',
                'role_name' => 'admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\TransferDiscrepancies',
                'page_name' => 'Transfer Discrepancies',
                'role_name' => 'manager',
            ],

            // Handover Discrepancies - Managers, Admins, Super Admin
            [
                'page_class' => 'App\Filament\Pages\HandoverDiscrepancies',
                'page_name' => 'Handover Discrepancies',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\HandoverDiscrepancies',
                'page_name' => 'Handover Discrepancies',
                'role_name' => 'admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\HandoverDiscrepancies',
                'page_name' => 'Handover Discrepancies',
                'role_name' => 'manager',
            ],

            // My Handover History - Bartenders and Chefs (own sessions only)
            [
                'page_class' => 'App\Filament\Pages\MyHandoverHistory',
                'page_name' => 'My Handover History',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHandoverHistory',
                'page_name' => 'My Handover History',
                'role_name' => 'bartender',
            ],
            [
                'page_class' => 'App\Filament\Pages\MyHandoverHistory',
                'page_name' => 'My Handover History',
                'role_name' => 'chef',
            ],

            // Shortage Reports - Managers, Admins, Super Admin
            [
                'page_class' => 'App\Filament\Pages\ShortageReports',
                'page_name' => 'Shortage Reports',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ShortageReports',
                'page_name' => 'Shortage Reports',
                'role_name' => 'admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ShortageReports',
                'page_name' => 'Shortage Reports',
                'role_name' => 'manager',
            ],

            // Manage Units - Managers, Admins, Super Admin
            [
                'page_class' => 'App\Filament\Pages\ManageUnits',
                'page_name' => 'Manage Units',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ManageUnits',
                'page_name' => 'Manage Units',
                'role_name' => 'admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ManageUnits',
                'page_name' => 'Manage Units',
                'role_name' => 'manager',
            ],
            // Reservations Timeline - Receptionists, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\ReservationsTimeline',
                'page_name' => 'Reservations Timeline',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReservationsTimeline',
                'page_name' => 'Reservations Timeline',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReservationsTimeline',
                'page_name' => 'Reservations Timeline',
                'role_name' => 'receptionist',
            ],

            // Folio Detail - Receptionists, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\FolioDetail',
                'page_name' => 'Folio Detail',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\FolioDetail',
                'page_name' => 'Folio Detail',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\FolioDetail',
                'page_name' => 'Folio Detail',
                'role_name' => 'receptionist',
            ],

            // Transfer Verification (hotel folio payments) - Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\TransferVerification',
                'page_name' => 'Transfer Verification',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\TransferVerification',
                'page_name' => 'Transfer Verification',
                'role_name' => 'manager',
            ],

            // Room Order - Receptionists, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\RoomOrder',
                'page_name' => 'Room Order',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\RoomOrder',
                'page_name' => 'Room Order',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\RoomOrder',
                'page_name' => 'Room Order',
                'role_name' => 'receptionist',
            ],

            // Porter Deliveries - Porters, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\PorterDeliveries',
                'page_name' => 'Porter Deliveries',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\PorterDeliveries',
                'page_name' => 'Porter Deliveries',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\PorterDeliveries',
                'page_name' => 'Porter Deliveries',
                'role_name' => 'porter',
            ],

            // Receptionist Shift - Receptionists, Super Admin
            [
                'page_class' => 'App\Filament\Pages\ReceptionistShift',
                'page_name' => 'Receptionist Shift',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\ReceptionistShift',
                'page_name' => 'Receptionist Shift',
                'role_name' => 'receptionist',
            ],

            // Room Board - Receptionists, Managers, Super Admin
            [
                'page_class' => 'App\Filament\Pages\RoomBoard',
                'page_name' => 'Room Board',
                'role_name' => 'super_admin',
            ],
            [
                'page_class' => 'App\Filament\Pages\RoomBoard',
                'page_name' => 'Room Board',
                'role_name' => 'manager',
            ],
            [
                'page_class' => 'App\Filament\Pages\RoomBoard',
                'page_name' => 'Room Board',
                'role_name' => 'receptionist',
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