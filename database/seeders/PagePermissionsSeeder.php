<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PagePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Page access permissions
            'access_pos',
            'access_floor_plan',
            'access_kitchen_display',
            'access_bar_display',

            // Report page permissions
            'access_reports',
            'access_inventory_reports',
            'access_staff_reports',
            'access_shift_reports',

            // Other page permissions
            'access_quick_inventory',
            'access_stock_transfers',
            'access_receive_transfers',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->command->info('Page permissions created successfully!');
    }
}