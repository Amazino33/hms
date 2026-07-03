<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ShieldSeeder::class,
            PagePermissionsSeeder::class,
            WarehouseTypeSeeder::class,
            ProductSeeder::class,
        ]);

        // Never create a known-password admin account or fake demo rows
        // against a live production database.
        if (! app()->environment('production')) {
            $user = User::firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => Hash::make('password')],
            );

            if (! $user->hasRole('super_admin')) {
                $user->assignRole('super_admin');
            }

            $this->call(DemoDataSeeder::class);
        }
    }
}