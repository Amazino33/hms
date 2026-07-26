<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRoomSupplyUsage;
use App\Models\RoomSupply;
use App\Models\RoomSupplyTransaction;
use App\Models\WareHouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock management for the room-supplies track (tissue, soap, etc.) —
 * mirrors QuickInventoryUpdate's lock-and-increment pattern for purchases,
 * and additionally records what was used against a specific stay.
 */
class RoomSupplyService
{
    public function recordPurchase(
        RoomSupply $roomSupply,
        WareHouse $warehouse,
        float $quantity,
        ?float $costPerUnit,
        int $userId,
        ?string $reference = null,
    ): RoomSupplyTransaction {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($roomSupply, $warehouse, $quantity, $costPerUnit, $userId, $reference) {
            $inventory = $roomSupply->inventory()
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            if ($inventory) {
                $inventory->increment('quantity', $quantity);
            } else {
                $roomSupply->inventory()->create([
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $quantity,
                ]);
            }

            if ($costPerUnit !== null) {
                $roomSupply->update(['cost_per_unit' => $costPerUnit]);
            }

            return RoomSupplyTransaction::create([
                'room_supply_id' => $roomSupply->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'purchase',
                'quantity' => $quantity,
                'cost_per_unit' => $costPerUnit,
                'reference' => $reference,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Deducts stock and records what was used against a specific stay, at
     * the room supply's current cost_per_unit (snapshotted onto the usage
     * row so a later cost change never rewrites a past stay's cost).
     */
    public function recordUsage(
        Booking $booking,
        RoomSupply $roomSupply,
        WareHouse $warehouse,
        float $quantity,
        int $userId,
    ): BookingRoomSupplyUsage {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($booking, $roomSupply, $warehouse, $quantity, $userId) {
            $inventory = $roomSupply->inventory()
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            $available = (float) ($inventory?->quantity ?? 0);

            if ($available < $quantity) {
                throw new RuntimeException(
                    "Not enough {$roomSupply->name} in stock at {$warehouse->name} — only {$available} {$roomSupply->unit} available."
                );
            }

            $inventory->decrement('quantity', $quantity);

            $transaction = RoomSupplyTransaction::create([
                'room_supply_id' => $roomSupply->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'usage',
                'quantity' => $quantity,
                'cost_per_unit' => $roomSupply->cost_per_unit,
                'reference' => "booking:{$booking->id}",
                'user_id' => $userId,
            ]);

            return BookingRoomSupplyUsage::create([
                'booking_id' => $booking->id,
                'room_supply_id' => $roomSupply->id,
                'quantity' => $quantity,
                'unit_cost_at_use' => $roomSupply->cost_per_unit,
                'room_supply_transaction_id' => $transaction->id,
                'recorded_by' => $userId,
            ]);
        });
    }
}
