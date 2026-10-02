# Phase 0C: Room Order Cancel and Folio Reversal Verification Report

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 0C build prompt. Read-only.
**Verdict:** **No cancel path exists for room orders, and their folio charge cannot be reversed.** A waste mechanism does exist (`kitchen_waste_logs`, from Phase 0D), and so does a general append-only reversal mechanism for folio lines. Neither STOP condition applies. Two decisions are needed before building (section 7).

---

## 1. Cancel/void paths for room orders: none

| Path | Can it reach a room order? |
|---|---|
| POS whole-table cancel (`pos.blade.php` `confirmCancelOrder()`) | **No.** It selects by `table_id`, and room orders have `table_id = null` |
| `TableDetail::cancelOrder()` | **No.** Same reason, plus own `pending` orders only |
| `VoidOrderItem` page → `UnreturnableVoidService::apply()` | **Partly.** It can reduce a line on any `pending/preparing/ready/served` order, room orders included. But it only edits `orders.total_amount`. **It never touches the folio**, so the guest is still charged the full amount |
| Return ticket (`submitReturnRequest()`) | **No.** It is created from a POS table |
| Admin `OrderResource` edit form (status select allows `cancelled`) | **Yes, but unsafe.** The observer handles stock correctly (Phase 0D rules), but **the folio charge stays**, so the guest is billed for a cancelled order |
| `RoomOrder`, `PorterDeliveries`, `RoomBoard`, `FolioDetail`, `ReceptionistShift` pages | No cancel action anywhere |

## 2. How a room order is charged to the folio

- `app/Filament/Pages/RoomOrder.php` `submitOrder()` → `app/Services/RoomOrderService.php` `placeOrder()`.
- `placeOrder()` → `OrderSplitter::handle()` (with `booking_id`, deferred stock), then **one** `FolioLine::create(['type' => 'order', 'amount' => sum of all split orders, 'description' => "Room order (ORD-…-K, ORD-…-B)", 'created_by' => …])`.
- It fires at **order creation**, before anything is cooked.
- Table `folio_lines`: `folio_id, type enum(room_charge, order, incidental, adjustment, payment, discount), amount, description, created_by, shift_id, payment_method, verified, verified_by, verified_at, reference, reversal_of_line_id, timestamps`.
- **There is no link from a folio line to its order(s).** The only connection is the order numbers in `description`. A mixed food-and-drinks room order is **one** line covering **two** orders.

## 3. Can folio charges be reversed, edited or deleted?

- **Append-only reversal already exists:** `folio_lines.reversal_of_line_id` (migration `2026_09_19_090000`), with `FolioLine::reversal()` (hasOne), `reversalOf()`, `isReversal()` and `isVoided()`. `FolioService::voidLine()` posts an equal and opposite line of the **same type**, locked under a transaction. It refuses a second void and refuses after checkout. It stores the reason in the description and in the activity log, with `created_by` as the voiding user.
- **But it is restricted to `payment` and `discount`** (`FolioService::voidLine()`, and `FolioDetail::canVoid()` in the UI). An `order` line **cannot be reversed anywhere**.
- **Edits:** `FolioService::voidLine()` (unverified transfer only) and `rejectTransfer()` update `verified/verified_by/verified_at/reference` on **payment** lines. No code updates or deletes an `order` or `room_charge` line. There are no deletes at all.
- **Balance and checkout gate:** `Folio::balance()` = `SUM(folio_lines.amount)`. `BookingService::checkOut()` throws if `balance() > 0.01`. A reversal line therefore nets out naturally.

## 4. Room order deduction timing

- **Food:** at Mark Ready, in `KitchenOrderService::markReady()` (since Phase 0D, all kitchen tickets with `stock_deducted_at` null).
- **Drinks:** also at **Mark Ready**, in `BarDisplay::markAsReady()` (`if ($order->booking_id) deductInventoryForOrderItems()`). Table drinks, by contrast, deduct at creation.
- Room orders then go `ready` → porter `pickUp()` → `confirmDelivered()` (`served`). They never become `paid`; the folio carries the money.

## 5. Waste / return-loss mechanism: yes

`kitchen_waste_logs` / `App\Models\KitchenWasteLog` (Phase 0D). `OrderObserver::updating()` writes one row per item, with **no stock movement**, whenever a **kitchen** order (room or table) moves to `cancelled` from any status past `pending/preparing`. A room order cancelled after Mark Ready gets this for free just by setting `status = cancelled`.

## 6. The existing table-order void flow and its permissions

| Flow | Gate |
|---|---|
| POS whole-table cancel (`confirmCancelOrder`) | **No role check.** Requires a `staff_pin` user with an active shift (any type) and a reason. Only the waiter whose table it is can open the table (soft check in `updatedSelectedTableId()`) |
| `TableDetail::cancelOrder()` | PagePermission on `TableDetail`. Own `pending` orders only |
| `VoidOrderItem` (comp/loss) | PagePermission on `VoidOrderItem`, labelled "Supervisor Only". `UnreturnableVoidService` does **not** re-check roles itself |
| Supervisor checks elsewhere | `hasRole(['manager', 'admin', 'super_admin'])` (`ServedConfirmationService`) |

**Stock on a table cancel:** drinks that deducted at creation are restocked by `OrderObserver` → `InventoryService::returnInventoryForCancelledOrder()`. Kitchen tickets follow the Phase 0D rules: no movement before Mark Ready (or a legacy restock), and waste with no restock after it. For a room drink, the same observer gives back exactly what Mark Ready deducted. Before bar Mark Ready nothing was deducted, so nothing comes back.

## 7. Decisions needed

1. **Who may cancel a room order?** There is no fixed role list to "reuse exactly". The table cancel has no role check at all, and the comp/loss void is gated only by a runtime PagePermission.
2. **One folio line covers several orders.** A mixed room order has a single charge for both its food and drink orders, so "reverse the charge once" does not map one-to-one onto "cancel an order".

The prompt asks for a new `reverses_folio_charge_id` column. **The existing `reversal_of_line_id` already does this**, so 0C will reuse it rather than add a second, parallel link. The reason goes in the description and the activity log, and `created_by` is the user who reversed it, exactly as the existing voids do.

## 8. Decisions (2026-10-01) and what was built

| Question | Owner decision |
|---|---|
| Who may cancel | **Split by Mark Ready.** Before it (nothing made, no loss): `receptionist`, `manager`, `admin` or `super_admin`. After it (a real loss): `manager`, `admin` or `super_admin` only. This is enforced in `RoomOrderService`, not just by the button |
| One combined charge | **One charge per order from now on.** A legacy combined charge cancels every order it names, together, and is reversed once |

What was built:
- **`folio_lines.order_id`** (nullable FK, migration `2026_10_01_110000`). `RoomOrderService::placeOrder()` now posts one `order` line per split order, naming it. Older lines are untouched and stay null.
- **`FolioService::reverseOrderCharge()`** posts an equal-and-opposite `order` line linked through the **existing** `reversal_of_line_id`, with the reason in the description and the activity log and `created_by` as the user who reversed it. It works only once, under a row lock, and refuses a sealed (checked-out) folio. No new `reverses_folio_charge_id` column was added, because the existing link already does this.
- **`RoomOrderService::cancel()`** checks the role rule, sets each order to `cancelled` with the reason, and reverses the charge, all in one transaction. Stock is handled by the existing `OrderObserver`:
  - before Mark Ready, nothing (nothing left the shelf);
  - room drinks after bar Mark Ready are restocked, exactly as a cancelled table drink is;
  - room food after kitchen Mark Ready is recorded in `kitchen_waste_logs` and never restocked.
- **Folio page (`FolioDetail`):** a "Room orders" panel lists the stay's orders with a "Cancel room order" button. It appears only to someone allowed to cancel that order at its current stage, and only before checkout. The reason is required.
- **`FolioLine` immutability at the model level:** deleting any line throws, and updating throws unless it is a `payment` line changing only the transfer-resolution fields (`verified`, `verified_by`, `verified_at`, `reference`). That is the existing behaviour of `verifyTransfer`, `rejectTransfer` and `voidLine`.
- **Checkout gate:** confirmed unchanged. `Folio::balance()` is a plain `SUM(amount)`, so a full reversal brings it to 0 and checkout passes (tested).
