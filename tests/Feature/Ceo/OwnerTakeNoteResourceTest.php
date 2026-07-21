<?php

use App\Models\OwnerTakeNote;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('lets the ceo role view Oga\'s Take Notes and see recorded data', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $waiter = User::factory()->create(['name' => 'Blessing']);
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    OwnerTakeNote::create([
        'shift_id' => $shift->id,
        'recorded_by' => $waiter->id,
        'amount' => 5000,
        'description' => 'Oga took 2 crates of beer',
    ]);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $response = $this->actingAs($ceo)->get('/ceo/owner-take-notes');

    $response->assertSuccessful();
    $response->assertSee('Blessing');
    $response->assertSee('Oga took 2 crates of beer');
});

it('does not let a bartender (no ceo role) reach the Oga\'s Take Notes page', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $response = $this->actingAs($bartender)->get('/ceo/owner-take-notes');

    $response->assertForbidden();
});
