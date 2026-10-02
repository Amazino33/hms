# Phase 0D: Food Deduction Timing Verification Report

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 0D build prompt ("Food deducts at Mark Ready, all paths"). Read-only.
**Verdict:** Dine-in food does **not** deduct at Mark Ready; it deducts at order creation. The rule was **never built** (it was not built and then undone). The STOP condition in the prompt is therefore not triggered. However, building Step 2 exactly as written would cause four stock-integrity problems (section 6). Those were put to the owner and decided before building (section 7).

---

## 1. Dine-in food: where kitchen stock is deducted today

| Step | Location |
|---|---|
| Waiter taps "Order" | `resources/views/livewire/pos.blade.php` `checkout()` (line 598) → `OrderSplitter::handle()` with `status = 'pending'` and no `defer_stock_deduction` |
| Split into a kitchen order | `app/Services/OrderSplitter.php` `handle()`. Menu items always go to `kitchen`. Products whose category `type = 'food'` also go to `kitchen` (kitchen warehouse) |
| **Deduction** | `app/Services/OrderSplitter.php:176-177`: `if (empty($options['defer_stock_deduction'])) { InventoryService::deductInventoryForOrderItems($order); }` |
| What runs | `app/Services/InventoryService.php:167` `deductInventoryForOrderItems()` → `deductMenuItemIngredients()` (line 238) writes `IngredientTransaction type=usage` per recipe ingredient, and `deductProductInventory()` (line 194) writes `InventoryTransaction type=sale` for food *Products*. It stamps `orders.stock_deducted_at`. |

"Food" therefore covers two different stock tracks inside a kitchen order: menu-item **recipe ingredients**, and kitchen-warehouse **food Products**. Both deduct at creation.

## 2. Room food: where the Mark Ready deduction happens

| Step | Location |
|---|---|
| Room order placed | `app/Services/RoomOrderService.php:32-35` → `OrderSplitter::handle()` with `defer_stock_deduction => true` |
| **Mark Ready (kitchen)** | `app/Services/KitchenOrderService.php:26` `markReady()`, inside a transaction with the order row locked, `status pending → ready`, then line 50: `if ($order->booking_id) { InventoryService::deductInventoryForOrderItems($order); }` |
| Callers | `app/Filament/Pages/KitchenDisplay.php` `markAsReady()` (admin panel) and `resources/views/livewire/kds-board.blade.php` `markReady()` (`/kds`) |
| Bar equivalent | `app/Filament/Pages/BarDisplay.php:99` `markAsReady()`, line 124, same `booking_id` gate (its own copy) |

The idempotency marker **already exists**: `orders.stock_deducted_at`, added in `database/migrations/2026_09_05_090000_add_stock_deducted_at_to_orders_table.php`. That migration **already backfilled** every historic order that has a `sale` or `usage` transaction referencing it. Because a kitchen order is already one destination's ticket, the order row *is* the kitchen ticket, so no new column is needed. Prompt Steps 2.1 and 2.2 are already satisfied.

One gap in the existing guard: `deductInventoryForOrderItems()` does not check `stock_deducted_at` before deducting. Double deduction on the room path is prevented only by `markReady()` requiring `status = 'pending'` under the row lock.

## 3. Git history: never built

- `git log -S "deductInventoryForOrderItems"` on `OrderSplitter.php`, `KitchenDisplay.php` and `KitchenOrderService.php`: the creation-time call was added in `ef27d04` (2026-01-29, "minor bug fix") and has **never been removed or made conditional on destination**.
- `git log -S "defer_stock_deduction"`: added in `b813558` (2026-07-14, hotel module), with the comment fixed in `a388ecc` (2026-09-06). It was only ever passed by `RoomOrderService`.
- The Mark Ready deduction was added in `1bc942d` (2026-07-14), gated on `$order->booking_id` from day one. `f51d06c` (2026-08-02, KDS) moved it unchanged into `KitchenOrderService`.
- No commit in the 375-commit history ever deferred dine-in kitchen deduction.

## 4. Dine-in void flows for food

The codebase has **four** separate ways to take food off a bill. None of them is called "void" in code except the third.

| Flow | Location | Before Mark Ready | After Mark Ready (cooked) |
|---|---|---|---|
| **Whole-table cancel** (reason required) | `pos.blade.php` `confirmCancelOrder()` (line 850); `TableDetail::cancelOrder()` (line 113, own `pending` orders only); admin `OrderResource` edit form (`status` select allows `cancelled`) | `OrderObserver::updating()` (line 28) → `InventoryService::returnInventoryForCancelledOrder()` (line 42). It **restocks**, because a dine-in order is deducted at creation | **Restocks ingredients and food Products.** This breaks "cooked food is never restocked" |
| **Return ticket** (chef confirms) | `pos.blade.php` `submitReturnRequest()` (line 1079) → `ReturnConfirmationService::confirm()` → status `returned` → same observer, `is_return` branch | n/a (only for served items) | **Restocks the recipe ingredients** of the returned dish. This breaks the same rule |
| **Unreturnable void** (manager: comp, complaint, loss, other) | `app/Filament/Pages/VoidOrderItem.php` → `UnreturnableVoidService::apply()` (line 24) | No stock movement. It reduces item quantity and order total, and writes `unreturnable_voids` | No stock movement. **This is the only existing "waste/loss record without restock"** |
| **Admin item edit** | `OrderResource` edit form, `Repeater::make('items')` | Items can be changed with no stock effect | Same |

**The existing waste mechanism.** `DamageReport` → `DamageReportService::approve()` writes `damage_write_off` transactions, which **deduct** stock. It exists for spoiled or broken shelf stock. Using it for a dish voided after Mark Ready would take its ingredients off a **second time**. It is not the right "record waste" mechanism for this case. `UnreturnableVoid` (reason `loss`) is the only existing record of a loss with no stock movement.

## 5. Every path that creates food orders, and when each deducts

| Path | Created as | Reaches Mark Ready? | Deducts |
|---|---|---|---|
| POS "Order" (kiosk `/kiosk/order`, staff phone `/staff/order`, admin `PosPage`) via `checkout()` | `pending` | Yes (KDS / KitchenDisplay) | **At creation** |
| POS full payment screen `processPayment()` (line 376), dine-in | Deletes the table's ready and served orders (line 483). Raw-increments `inventory_items` for **Products only** (line 479, no `InventoryTransaction`). Re-creates all items via `OrderSplitter` as `paid`/`partial` | **No** (KDS filters `pending/preparing/ready`) | **At (re)creation.** Pre-existing bug: menu-item **ingredients are deducted twice**, because the raw restock skips menu items (`product_id` is null) and the re-creation deducts again |
| POS full payment screen, **takeaway** | `paid`/`partial` | **No.** It is never `pending`, so it never appears on KDS or KitchenDisplay | **At creation** |
| Room order (`RoomOrderService`) | `pending` + `booking_id` | Yes | **At Mark Ready** |
| Floor plan `FloorPlanController::addItemToOrder()` (line 59) | Adds items to an existing order | n/a | **Never** (Phase 0B, out of scope) |
| Return ticket | `pending`, `is_return` | n/a | Never deducts. It restocks on confirm |

## 6. What building Step 2 exactly as written would break

1. **"Record a waste event using the existing mechanism."** The existing mechanism (`DamageReport` / `damage_write_off`) deducts stock. For food already deducted at Mark Ready, that is a double deduction. A no-movement record is needed: `UnreturnableVoid`, or something new.
2. **"All paths at Mark Ready."** Takeaway kitchen orders and the full payment screen's re-created orders are created `paid`/`partial` and **never** pass through Mark Ready. Deferring their deduction means their food **would never deduct at all**.
3. **Shortage at Mark Ready.** Today an out-of-stock food Product, or short ingredients with enforcement on, is refused when the waiter orders. After the move, the same exception fires when the **chef marks a cooked dish ready**. `KitchenDisplay::markAsReady()` has no try/catch, so on the admin page that surfaces as a 500 error page.
4. **The cancel path cannot tell cooked from legacy using `stock_deducted_at` alone.** After deploy, both "deducted at creation, not yet cooked" (legacy) and "deducted at Mark Ready, cooked" have it set. The order status (`pending` versus `ready/served`) is what tells them apart. This works without a new column, but it changes `OrderObserver`/`InventoryService::returnInventoryForCancelledOrder()`, which the prompt lists as pipeline internals not to touch.

Also out of scope but adjacent: return tickets for a cooked dish restock its ingredients, which breaks the same "cooked food is never restocked" rule. The prompt does not say whether returns change too.

## 7. Decisions (2026-10-01) and what was built

| Question | Decision |
|---|---|
| Waste record for cooked food cancelled after Mark Ready | **New kitchen-waste log** (`kitchen_waste_logs`). No stock movement |
| Kitchen orders created already paid (takeaway, full payment screen) | **Keep deducting at creation.** Only `pending` kitchen tickets move to Mark Ready |
| Shortage at Mark Ready | **Never block.** Stock goes negative, and the shortfall is written to the activity log |
| Return tickets for cooked dishes | **Left for a later phase** (they still restock ingredients) |

What was built, and how it maps to the prompt:
- **Step 2.1 (idempotency marker):** reused the existing `orders.stock_deducted_at`. `KitchenOrderService::markReady()` now deducts only when it is null, reading the row it has just locked.
- **Step 2.2 (backfill):** no new migration. The 2026-09-05 migration already stamped every historic deducted order, and every order created since then is stamped at creation. Legacy pending tickets are therefore skipped at Mark Ready.
- **Step 2.3 (move):** `OrderSplitter` skips the creation deduction when `destination = 'kitchen'` and `status = 'pending'`. `KitchenOrderService::markReady()` is the one deduction point for every kitchen ticket, dine-in and room alike. It calls the existing `InventoryService::deductInventoryForOrderItems()`, now with an opt-in `allowShortfall`.
- **Step 2.4 (void):** `OrderObserver::updating()` treats a kitchen ticket cancelled from any status past `pending/preparing` as cooked: it writes `kitchen_waste_logs` rows and does not restock. Earlier cancellations go through the unchanged return path, whose `stock_deducted_at` guard restocks legacy tickets and moves nothing for new ones.
- **Step 2.5:** bar and drink timing is unchanged.

The "never block" rule applies to room food at Mark Ready too, so every kitchen path behaves the same. Room food still deducts at Mark Ready, exactly once.

Not changed, and still open:
- Return tickets restock cooked ingredients (deferred by decision).
- The full payment screen double-deducts menu-item ingredients when it re-creates orders (pre-existing; separate audit).
- An admin who sets a kitchen order to `ready` through the Orders edit form bypasses Mark Ready, so its stock never deducts (pre-existing bypass; the edit form has no stock logic).
- `kitchen_waste_logs` has no screen yet.
