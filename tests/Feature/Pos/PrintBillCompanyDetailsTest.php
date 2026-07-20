<?php

use App\Models\Company;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('includes company name, address, and phone (no logo) in the print-bill dispatch', function () {
    Company::create(['name' => 'Tiano Hotels and Suite', 'address' => '44 Marina Road, Eket', 'phone_number' => '+234 814 473 4612']);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('printBill', ['1' => ['name' => 'Beer', 'price' => 500, 'qty' => 2]])
        ->assertDispatched('print-bill', function ($name, $params) {
            return $params[0]['company']['name'] === 'Tiano Hotels and Suite'
                && $params[0]['company']['address'] === '44 Marina Road, Eket'
                && $params[0]['company']['phone'] === '+234 814 473 4612';
        });
});

it('falls back to null company fields instead of erroring when no Company record exists', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('printBill', ['1' => ['name' => 'Beer', 'price' => 500, 'qty' => 2]])
        ->assertDispatched('print-bill', function ($name, $params) {
            return $params[0]['company']['name'] === null
                && $params[0]['company']['address'] === null
                && $params[0]['company']['phone'] === null;
        });
});
