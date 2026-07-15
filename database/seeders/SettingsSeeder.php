<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::firstOrCreate(
            ['key' => 'allow_shift_start_with_unsettled'],
            ['value' => '0', 'type' => 'boolean'],
        );
    }
}
