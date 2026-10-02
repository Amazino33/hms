# Phase 7B — Step 1 verification

Read-only check before merging guest drinks into the bar display's normal queue. Date: 2026-10-02.

**Result: no STOP. Marking a freshly created table bar order ready does not deduct stock a second time.**

## 1. The shared bar ready service

`App\Services\BarOrderService::markReady(int $orderId, ?int $actorUserId): Order`, extracted from `BarDisplay::markAsReady()` in Phase 5. It:

- locks the order, scoped to `status = pending` and `destination = bar` (anything else is `ModelNotFoundException`, so it can never flip an order back);
- sets `status = ready` and `processed_by_user_id = actor`;
- **deducts stock only when `booking_id` is set** (room orders, which `RoomOrderService` creates with `defer_stock_deduction`);
- the page keeps the cache clearing and the "Ready!" notifications.

| Bar order | Stock leaves | `markReady()` does |
|---|---|---|
| **Table** (OrderSplitter, no `defer_stock_deduction`, destination bar) | at creation; `OrderSplitter` runs `deductInventoryForOrderItems()` and stamps `stock_deducted_at` | **status only**, no deduction (no `booking_id`) |
| **Room** (`RoomOrderService::placeOrder()`, deferred) | at ready | status, plus `deductInventoryForOrderItems()` |

`InventoryService::deductInventoryForOrderItems()` has **no** "already deducted" guard of its own. The guard is the `booking_id` check above. A table order never reaches the deduction branch, so calling `markReady()` on a just-created table bar order cannot deduct twice. **Step 1.5: no double deduction, so no STOP.**

## 2. `GuestBarReleaseService::release()` today

- **All:** locks the request and its `at_bar` lines. It refuses if none are left (double tap). D1 picks the credited bartender: no shift means refused, one shift is credited automatically, two or more need a choice.
- **Tables:** D3 checks the assigned waiter's active shift. With none, the lines become `needs_waiter`, the session's waiter is cleared, and it returns `returned_to_waiters` with no order. Otherwise `GuestRequestService::placeOrders()` → `OrderSplitter` with the guest marker (D18 price, chips, note), under the **waiter's** id and shift (D2). The lines become `released`, with `released_by_user_id` and `released_at`. **The bar order is left `pending`**: today the bartender must then tap **Mark Ready** on the normal queue, which is the second tap.
- **Rooms (D24):** `releaseToRoom()` runs `placeOrders()` → `RoomOrderService::placeOrder()` (folio charge with the ref). It then **already calls `BarOrderService::markReady()`** for each bar order, in the same transaction, and queues the lines for a porter (`RoomDeliveryService::readyForDispatch()`). A second ready call would hit `ModelNotFoundException` (the order is no longer pending), so Phase 7B must skip orders that are already ready.

## 3. The Bar Display page

- **Normal queue:** `BarDisplay::getViewData()['orders']` is every `pending` + `bar` order, `oldest()`, cached 5 s (`bar_display:active_orders`). It renders in `resources/views/filament/pages/bar-display.blade.php` (a CRLF file) as a 3-column card grid with `wire:poll.5s`.
  - Return tickets get a red **CONFIRM & RESTOCK** card.
  - Every other order gets the standard card: order number, age, origin, waiter, drink lines with chips/note, and a blue **MARK READY** button calling `markAsReady($id)`.
- **Guest column:** a separate `<aside>` holding the `bar-guest-queue` Livewire component, also `wire:poll.5s`. It has:
  - its own sound bar and banners;
  - cards sorted by `confirmed_at`, each with a green **Release** button (or the D1 chooser), −/✕ with the reason sheet;
  - the "Returned from rooms" strip.
- **Ordering today:** two separate lists, so guest cards and normal orders are never interleaved.

## 4. User-facing "release" wording

| Where | Text |
|---|---|
| `bar-guest-queue.blade.php` | **Release** button (×2), **Who is releasing?**, banner "… start your shift to release them.", toasts "Couldn't release R7-…", "Room 7 released", "Table 5 released", "Only bar staff and managers can release guest orders." |
| `GuestBarReleaseService` | refusals "Start your bartender shift to release guest orders.", "More than one bartender shift is open — choose who is releasing." |
| `GuestBarMonitor` (manager alerts) | "… to release them.", "Guest drinks can only be released once the overlap is cleared…" |
| Guest status labels (`GuestBillService::lineStatus`) | released drink line → "On the way" (Phase 7B: → **"Ready"**) |
| `docs/training/` (Phase 6 ran) | `roles/bartender.md` (6 places); `dry-run.md` scenarios 1, 3b, 4, 5, 6, 7, 13, 17, 19; `label-check.md`; the print HTML |

Code-only names (`GuestBarReleaseService`, `release()`, `released`, `released_by_user_id`, `released_at`) are not user-facing and stay.

## 5. Double deduction (the STOP check)

None. See §1: `BarOrderService::markReady()` deducts only for room orders, and table bar orders deducted at creation.
