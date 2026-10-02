# Phase 3: Verification Report (Step 1)

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 3 build prompt (waiter acceptance, bar queue and release, alerts, bar-shift nudge). Read-only.
**Verdict:** **No STOP.** `OrderSplitter` lacks per-line price, chips and note (as Phase 2 reported), but they can be added as **optional, guest-only** fields that leave every existing caller byte-for-byte unchanged. **Part A is needed**, and it needs one more optional field than the prompt lists (section 3).

---

## 1. Waiter kiosk and PIN identity

- **Screens:** `/kiosk` and `/staff` → `resources/views/livewire/kiosk-idle-screen.blade.php` (the table grid plus PIN pad). After a PIN they go to `/kiosk/order/{table}` → `kiosk-order-wrapper` → `pos.blade.php`.
- **PIN identity:** `kiosk-idle-screen::submitPin()` → `PinAuthService::attempt($pin, $throttleKey)` returns the `User`, then `Auth::guard('staff_pin')->login()` and a redirect. Livewire follow-up requests re-assert `Auth::shouldUse('staff_pin')` in `boot()` (`pos.blade.php`, `kds-board.blade.php`).
  - Throttle keys: `kiosk:{deviceId}` or `trusted:{userId}`.
  - On a trusted personal phone, the PIN must belong to the device's owner.
- **"Active waiter shift":** `OrderSplitter::assertShiftsActive()` uses `Shift::query()->where('user_id', …)->activeNonStale('waiter')`. Note that `User::currentShift()` uses the **non-stale-aware** `active()` scope. Phase 3 uses `activeNonStale('waiter')`, the rule order creation enforces.
- **For Phase 3:** a guest-request accept identifies the waiter by **PIN per action** (`PinAuthService::attempt`), without logging the kiosk into `staff_pin`. The shared table grid stays PIN-free.

## 2. Waiter end-shift flow

Waiters start and end shifts **only in the admin panel**, through the top-bar `App\Livewire\ShiftManager` (rendered via `AdminPanelProvider`): `confirmShiftEnd()` → **`User::endShift()`**. That already refuses on outstanding orders and pending returns, and refuses bartender, chef and receptionist shifts. **There is no end-shift control on the kiosk or the staff phone.** The D3 block therefore goes into `User::endShift()`, and its Hand over UI into `ShiftManager`.

## 3. `OrderSplitter::handle()`

- **Signature:** `handle(array|Collection $cart, ?int $tableId, int $userId, array $options = []): array` (the created `Order`s, one per destination).
- **Cart:** keyed `"menu_{id}"` (menu item) or `"{productId}"` (product) => `['name', 'price', 'quantity']`. `price` is **always overwritten** with the current `sale_price`/`price`.
- **Options:** `status`, `amount_paid`, `paid_cash`, `paid_pos`, `payment_method`, `guest_id`, `shift_id`, `processed_by_user_id`, `kiosk_device_id`, `booking_id`, `defer_stock_deduction`.
- **Per-line price: no. Chips/note: no.** `OrderItem::create()` writes neither.
- **Additional gap:** the item identity comes **only from the cart key**, so two lines for the same item collapse into one key. A guest request can legitimately hold `1× Beer (Cold)` and `1× Beer (Not cold)`. Part A therefore also adds an optional per-line `line_type`/`line_id`, honoured only with the guest marker, so guest lines can use unique keys.
- **Callers:**
  - `pos.blade.php` `checkout()` (pending table order)
  - `pos.blade.php` `processPayment()` (re-creation, paid/partial, `defer_stock_deduction`)
  - `RoomOrderService::placeOrder()` (room, `booking_id`, `defer_stock_deduction`)
  - tests

  None of them sends the new fields.

## 4. Bartender shifts

`Shift::query()->activeNonStale('bartender')` means `ended_at IS NULL` and `started_at >= now() − Shift::STALE_AFTER_HOURS` (**72 h**), filtered by `type`. `BartenderChefShiftService` only opens a shift from a reviewed opening count and refuses a second shift for the **same** user. **Two different bartenders can be active at once (D1).**

## 5. BarDisplay

- **Page:** `app/Filament/Pages/BarDisplay.php` + `resources/views/filament/pages/bar-display.blade.php`. An admin-panel page on the `web` guard, so the acting user is whoever is logged in.
- **Layout:** a 3/4 + 1/4 grid. Pending bar orders are in a `wire:poll.5s` grid; history and the fridge restock list sit beside them.
- **Permissions** (`PagePermissionsSeeder`): **super_admin, manager, bartender**.

## 6. Bartender handover seal

`CountSessionService` (including `sealAgreement()`) and `BartenderChefShiftService` contain **no reference** to `guest_request*` (grep). Unreleased guest lines are untouched by a handover and carry over.

## 7. Notification helper for /admin alerts

- **Flash:** `App\Services\UserFeedback::blocked()/failed()/succeeded()` (Filament flash notifications).
- **Persistent /admin alerts:** `Filament\Notifications\Notification::make()->…->sendToDatabase($user)`, shown in the admin bell, which polls every 20 s (`AdminPanelProvider::databaseNotificationsPolling('20s')`). The supervisor pattern is `User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'admin', 'super_admin']))` (e.g. `OrderPaymentVerificationService::notifySupervisors()`).

## 8. Table identity on orders

`orders.table_id` (FK to `tables`). The POS bill for a table is every order with that `table_id` in `pending/preparing/ready/served`.

## 9. Earliest refusal point in `processPayment()`

Right after `$isTakeaway` / `$tableId` are computed. That is after the shift, auth, empty-cart and split-amount checks, and **before** "STRICT RULE 2" and long before the Phase 0F delete/recreate transaction. A D17 guard there changes nothing else.
