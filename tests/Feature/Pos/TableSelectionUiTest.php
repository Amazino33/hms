<?php

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('shows a color-coded table button grid instead of a dropdown', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertSee('Table 1')
        ->assertSee('Take Away');
});

it('prompts to select a table before allowing cart interaction when no table can be auto-selected', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    // No tables exist at all, so nothing can be auto-selected.
    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertSee('required before adding items');
});

it('removes the "select a table" prompt once a table is chosen', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', 'takeaway')
        ->assertDontSee('required before adding items');
});
