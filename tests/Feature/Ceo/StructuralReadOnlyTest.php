<?php

use App\Filament\Ceo\Resources\Folios\FolioResource;
use App\Filament\Ceo\Resources\HandoverCounts\HandoverCountResource;
use App\Filament\Ceo\Resources\InventoryTransactions\InventoryTransactionResource;
use App\Filament\Ceo\Resources\Orders\OrderResource;
use App\Filament\Ceo\Resources\Procurements\ProcurementResource;
use App\Filament\Ceo\Resources\ReceptionistShiftSettlements\ReceptionistShiftSettlementResource;
use App\Filament\Ceo\Resources\Reservations\ReservationResource;
use App\Filament\Ceo\Resources\StaffDebts\StaffDebtResource;
use App\Filament\Ceo\Resources\WaiterShiftSettlements\WaiterShiftSettlementResource;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

$resources = [
    OrderResource::class, WaiterShiftSettlementResource::class, ReceptionistShiftSettlementResource::class,
    FolioResource::class, ReservationResource::class, HandoverCountResource::class,
    StaffDebtResource::class, InventoryTransactionResource::class, ProcurementResource::class,
];

it('registers no create/edit/delete routes anywhere in the CEO panel', function () {
    $ceoRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with($route->getName() ?? '', 'filament.ceo.')
    );

    expect($ceoRoutes)->not->toBeEmpty();

    foreach ($ceoRoutes as $route) {
        expect($route->methods())->not->toContain('DELETE');
        expect($route->methods())->not->toContain('PUT');
        expect($route->methods())->not->toContain('PATCH');
        expect($route->getName())->not->toEndWith('.create');
        expect($route->getName())->not->toEndWith('.edit');
    }
});

it('every CEO resource reports itself as structurally non-mutating', function () use ($resources) {
    foreach ($resources as $resourceClass) {
        expect($resourceClass::canCreate())->toBeFalse();
        expect($resourceClass::getPages())->not->toHaveKey('create');
        expect($resourceClass::getPages())->not->toHaveKey('edit');
    }
});

it('rejects a POST attempt against a CEO resource index path with 404 or 405, never a mutation', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $user = User::factory()->create();
    $user->assignRole('ceo');

    $response = $this->actingAs($user)->post('/ceo/orders');

    expect($response->status())->toBeIn([404, 405]);
});

it('rejects an attempt to reach a would-be create route for a CEO resource', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $user = User::factory()->create();
    $user->assignRole('ceo');

    $response = $this->actingAs($user)->get('/ceo/orders/create');

    expect($response->status())->toBe(404);
});
