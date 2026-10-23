<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Announcement;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Guest\GuestSessionHandoverService;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

/**
 * Staff 0A §4.7 — operational pickers use User::active(); history still
 * shows deactivated staff by name.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);

    $this->current = User::factory()->create(['name' => 'Current Waiter']);
    $this->current->assignRole('waiter');

    $this->leaving = User::factory()->create(['name' => 'Leaving Waiter', 'left_at' => now()->addDays(3)]);
    $this->leaving->assignRole('waiter');

    $this->gone = User::factory()->create(['name' => 'Gone Waiter', 'left_at' => now()->subDay()]);
    $this->gone->assignRole('waiter');
});

it('scopes active and deactivated by the date, not by whether left_at is set', function () {
    expect(User::active()->pluck('name')->all())->toContain('Current Waiter', 'Leaving Waiter')->not->toContain('Gone Waiter');
    expect(User::deactivated()->pluck('name')->all())->toBe(['Gone Waiter']);
});

it('leaves deactivated staff out of the operational lists', function () {
    // Announcements audience.
    $announcement = Announcement::create(['title' => 'Notice', 'body' => 'x', 'audience' => 'all', 'created_by' => $this->current->id]);
    $audience = app(AnnouncementService::class)->audienceUserIds($announcement)->all();
    expect($audience)->toContain($this->current->id, $this->leaving->id)->not->toContain($this->gone->id);

    // The "no schedule" warning list.
    $unscheduled = app(ShiftAssignmentService::class)->staffWithoutSchedule()->pluck('id')->all();
    expect($unscheduled)->toContain($this->current->id, $this->leaving->id)->not->toContain($this->gone->id);

    // My Handover Count's incoming-colleague list (bartender role here).
    $this->current->syncRoles(['bartender']);
    $this->gone->syncRoles(['bartender']);
    $this->actingAs($this->leaving->syncRoles(['bartender']));
    $candidates = (new \App\Filament\Pages\MyCount)->candidateIncomingUsers()->pluck('id')->all();
    expect($candidates)->toContain($this->current->id)->not->toContain($this->gone->id);
});

it('refuses handing a guest table to a deactivated waiter, even with a shift still open', function () {
    Shift::create(['user_id' => $this->gone->id, 'type' => 'waiter', 'started_at' => now()->subHour(), 'status' => 'active']);

    $session = new \App\Models\GuestTableSession(['assigned_waiter_user_id' => $this->current->id]);

    expect(fn () => app(GuestSessionHandoverService::class)->handover($session, $this->current, $this->gone))
        ->toThrow(\Exception::class, 'no longer active');
});

it('still shows deactivated staff by name in history and in the users list', function () {
    $order = Order::factory()->create(['user_id' => $this->gone->id]);
    expect($order->fresh()->user->name)->toBe('Gone Waiter');

    $super = User::factory()->create();
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->current, $this->leaving])
        ->assertCanNotSeeTableRecords([$this->gone])
        ->filterTable('status', 'deactivated')
        ->assertCanSeeTableRecords([$this->gone]);
});
