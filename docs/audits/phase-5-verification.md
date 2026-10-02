# Phase 5 — Step 1 verification

Read-only check of the room flows before turning on guest room ordering. Date: 2026-10-02.

**Result: three points need a decision before building (marked ⚠). Nothing else conflicts with D24–D29.**

---

## 1. Room order creation ⚠ (shift requirement: venue-level only)

**Entry point:** `App\Services\RoomOrderService::placeOrder(int $roomId, array $cart, int $userId): array`

**Input:**
- `$cart` has OrderSplitter's shape: keyed by product id or `menu_{id}`, each entry `['name', 'price', 'quantity']`.
- `$userId` becomes `orders.user_id`.
- No shift is passed, so `shift_id` is null.

**What it does:**
- Looks up `Booking::where('room_id')->currentlyCheckedIn()`; with no checked-in stay it refuses.
- Calls `OrderSplitter::handle($cart, null, $userId, ['booking_id' => …, 'defer_stock_deduction' => true, 'status' => 'pending'])`.
- Posts **one folio charge per order** (Phase 0C).

**Identity and shift:**
- The caller needs **no shift of their own**. `OrderSplitter::assertShiftsActive()` skips the waiter-shift check whenever `booking_id` is set.
- **But the venue must have someone on duty:**
  - a **bar** order needs *some* active, non-stale **bartender** shift;
  - a **kitchen** order needs *some* active **chef** shift, unless the company toggle `require_chef_shift_for_kitchen_orders` is off.
- The receptionist's role is not checked. The page gate (`RoomOrder`, `PagePermission`) is what restricts it.

**Assessment:** the prompt says to STOP if room orders need a waiter or bartender shift. **No waiter shift is needed.** The bartender-shift rule is venue-level, not the actor's. Room drinks are only created at the bartender's Release, which already requires an open bartender shift (D1), so the rule is always satisfied there. Food approval inherits the chef-shift rule; when no chef is on shift, reception sees OrderSplitter's own message.

**⚠ Gap: `placeOrder()` can't do what approval needs.**
- It takes no splitter options. The guest's price lock (`unit_price_snapshot`, D18), the chips and the note only reach OrderSplitter with the guest source marker.
- It takes no description text.
- **Smallest additive option:** two optional trailing parameters, `array $options = []` (merged into the splitter options) and `?string $chargeNote = null`. Existing callers pass neither and behave exactly as today.

## 2. When the folio charge posts, and its description

- **When:** **at order creation**, inside `placeOrder()`, not at Mark Ready.
  - Room drinks charge at Release (D24, the order is created there).
  - Room food charges at reception approval.
- **Description:** `"Room order ({$order->order_number})"`. It is built in **`RoomOrderService::placeOrder()`**, which writes `FolioLine::create()` directly. **`FolioService` is not involved.**
- **Can the ref (e.g. `R7-0423`) appear without editing `FolioService`?** Yes.
  - The text is built in `RoomOrderService`, so the optional `$chargeNote` above gives `"Room order (KIT-0042) · R7-0423"`.
  - Existing callers keep `"Room order (KIT-0042)"`.
  - **`FolioService` needs no change.**

## 3. Room drinks' Mark Ready ⚠ (the entry point is a page method)

- **Where:** room drinks are marked ready on the **bar display**, by the bartender.
- **Entry point:** `App\Filament\Pages\BarDisplay::markAsReady($orderId)`. It:
  - locks a `pending` + `bar` order;
  - sets `ready` and `processed_by_user_id = auth()->id()`;
  - for room orders (`booking_id` set), calls `InventoryService::deductInventoryForOrderItems($order)` (no shortfall allowance);
  - then clears two caches and sends "Ready!" database notifications.
- **There is no service** for bar Mark Ready. `KitchenOrderService` says so explicitly ("BarDisplay keeps its own separate markAsReady() untouched").
- D24 asks for Release to "run the existing Mark Ready entry point in the same transaction". That entry point is a Livewire page method that depends on `auth()`, so a service can't call it.
- **Options:**
  - (a) Extract its core into a small `BarOrderService::markReady()` that `BarDisplay` then calls, the same way `KitchenOrderService` was extracted from `KitchenDisplay`.
  - (b) Repeat the two steps inside the guest release.
- **Kitchen** (room food) is `KitchenOrderService::markReady()`, called from `KitchenDisplay` and `/kds`. It **fires no event of its own**. The listener for D24's food side uses the Order model's `updated` event (`pending → ready`, destination `kitchen`, `booking_id` set) through a **new, separate observer**. `OrderObserver` and `KitchenOrderService` stay untouched.

## 4. The current checked-in stay, and checkout

- **The stay:** `App\Models\Booking` (rooms are stays; there is no separate stay model). Status field `bookings.status`: `reserved` / `checked_in` / `checked_out`.
- **Current stay for a room:** `Booking::where('room_id', $room->id)->currentlyCheckedIn()->first()`. The scope is `status = checked_in`, `check_in ≤ today ≤ check_out`.
- **Room changes:** `BookingService::changeRoom()` moves a stay to another room, so the room sticker resolves to whatever stay is currently in that room. Requests are tied to the booking (`stay_id`), not the room.
- **Checkout:** `BookingService::checkOut(Booking, int $userId)`.
  - It is gated only on folio balance ≤ ₦0.01.
  - It sets `status = checked_out` and writes a snapshot.
  - It **fires no event**.
  - **Plan:** a new Booking observer reacts to `status` changing to `checked_out` and cancels the stay's pending and at-bar guest requests (`checked_out`). `BookingService` stays untouched.

## 5. Folio payments

- **Flow:** `FolioService::recordPayment(Folio $folio, float $amount, string $method, ?string $reference, int $userId)`.
  - Methods: `cash` / `transfer` / `pos_terminal`.
  - A transfer is posted unverified, for a manager's `verifyTransfer()`.
  - The reference is free text.
- **UI:** `FolioDetail::recordPayment()` (`?booking={id}`), with `paymentAmount`, `paymentMethod`, `paymentReference`.
- **Plan:** "Open folio payment" on Room Orders opens a pre-filled form (amount, `transfer`, payer name as reference). Saving calls `FolioService::recordPayment()` unchanged, then sets the claim to `matched` in the same transaction.

## 6. Phone on bookings

- `bookings` has **no phone column**.
- The guest's phone is **`guests.phone`** (nullable), reached as `$booking->guest->phone`.

## 7. Reception's working surface

- Every Filament page is on the **web** guard (`admin` panel).
- `PagePermissionsSeeder` gives `receptionist`:
  - `ReservationsTimeline`, `FolioDetail`, `RoomOrder` (the staff room-order flow), `ReceptionistShift`, `RoomBoard`, `MyPayslips`, `RoomSupplies`.
- Each page gates itself with `PermissionService::canAccessPage(self::class)`.
- **Plan:** the new `RoomOrders` page follows the same pattern, seeded for `receptionist`, `manager` and `super_admin`. No other existing rows are touched.

## 8. Porters ⚠ (an existing porter flow overlaps)

- **The role:** `porter` exists (payroll, notifications, `PagePermissionsSeeder`).
- **Active porters:** `User::role('porter')->whereNull('left_at')`, the same query `GuestOrdering` already uses.
- **⚠ There is an existing porter delivery flow:** `App\Filament\Pages\PorterDeliveries` + `App\Services\PorterDeliveryService`.
  - Every room order at status `ready` appears there.
  - A porter taps **Pick up** (`orders.picked_up_by/at`, the porter themselves), then **Delivered**, which sets `orders.status = served`.
  - Only the porter who picked it up, or a supervisor, can confirm.
- Phase 5's `RoomDeliveryService` (reception dispatches lines to a porter, marks Delivered/Refused) would track the **same room orders** a second way. Guest room orders would show on both pages, and the two could disagree. For example, a porter marks it delivered there while reception marks it refused here.

## 9. Phase 2's room refusal

`GuestRequestService::submit()`, right after the token resolves:

```php
if ($place instanceof Room) {
    throw new GuestRequestException('rooms_coming_soon', 'Ordering from your room is coming soon — call reception to order.', 422);
}
```

The page also renders rooms in `browse` mode with the same notice (`GuestMenuController::place()`). `GuestWaiterCallService::create()` refuses rooms as well ("Please call reception from your room phone"). All three get replaced.

---

## Decisions (owner, 2026-10-02)

1. **Bar Mark Ready (D24):** extract the core of `BarDisplay::markAsReady()` into `BarOrderService::markReady()`. The page calls it with the same behaviour, and the guest Release calls it inside its transaction.
2. **Porter flow:** guest room orders have one delivery flow, Room Orders. `RoomDeliveryService` keeps the order fields in step:
   - dispatch stamps `picked_up_by/at`;
   - Delivered sets the order to `served`.

   Porter Deliveries hides orders that came from a guest request. Staff-placed room orders are unchanged.
3. **`placeOrder()`:** gains two optional trailing parameters, `array $options = []` and `?string $chargeNote = null`. Existing callers are unchanged. **`FolioService` needs no change.**
