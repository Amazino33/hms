<?php

use App\Models\Table as TableModel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

it('logs the staff_pin session out when the wrapped pos component dispatches order-completed', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);
    expect(Auth::guard('staff_pin')->check())->toBeTrue();

    Livewire::test('kiosk-order-wrapper', ['table' => $table->id])
        ->dispatch('order-completed')
        ->assertRedirect(route('kiosk.home'));

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});

it('logs the staff_pin session out when the inactivity timeout fires', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);

    Livewire::test('kiosk-order-wrapper', ['table' => $table->id])
        ->call('discardAndReturn')
        ->assertRedirect(route('kiosk.home'));

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});

it('logs the staff_pin session out when the pos component dispatches lock-requested', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);

    Livewire::test('kiosk-order-wrapper', ['table' => $table->id])
        ->dispatch('lock-requested')
        ->assertRedirect(route('kiosk.home'));

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});

it('positions notifications top-center on the kiosk order screen so they are not missed', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);

    Livewire::test('kiosk-order-wrapper', ['table' => $table->id]);

    expect(\Filament\Notifications\Livewire\Notifications::$alignment)->toBe(\Filament\Support\Enums\Alignment::Center);
    expect(\Filament\Notifications\Livewire\Notifications::$verticalAlignment)->toBe(\Filament\Support\Enums\VerticalAlignment::Start);
});
