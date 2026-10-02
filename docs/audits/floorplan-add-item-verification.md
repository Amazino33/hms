# Phase 0B: Floor-Plan "Add Item" Verification Report

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 0B build prompt. Read-only.
**Verdict:** **Confirmed: the add-item endpoint takes no stock.** But it is an **orphan**. No screen, script or button has ever called it, from the day it was added (2026-01-25) to now. Production leak sizing (section 5) needs the owner to run two read-only queries; locally it is 0.

---

## 1. The floor-plan add-item flow, end to end

| Layer | Location |
|---|---|
| Route | `routes/web.php:140`, `POST /admin/floor-plan/add-item`, inside `Route::middleware(['auth'])` (the `web` guard only: no PagePermission, no Shield policy, no shift check) |
| Controller | `app/Http/Controllers/FloorPlanController.php` `addItemToOrder()` |
| Services | **None.** It calls only `InventoryService::checkMenuItemIngredientsAvailability()` (a read-only check, enforced only when `enforce_kitchen_ingredient_stock` is on) and `enforceIngredientStock()` |
| Models | `Order::find()` (it refuses unless `order.user_id === auth()->id()`), then either `OrderItem` update (quantity += n) or `$order->items()->create([...])`, then `$order->update(['total_amount' => …])` |
| Caller | **None.** No Blade view, Livewire component, JS file or public asset references `add-item`, `addItemToOrder` or `/admin/floor-plan/`. `git log -S "/admin/floor-plan/"` over `resources/` and `public/` returns nothing. The only commit containing the route is `7e01623` (2026-01-25, "POS Table Management & Order System"), which added the route and controller but no caller |

The same is true of its two siblings, `GET /admin/floor-plan/order/{orderId}` (`getOrderDetails`) and `GET /admin/floor-plan/popular-items` (`getPopularItems`). Both are read-only and both are uncalled.

**How items are really added from the floor plan:** `app/Filament/Pages/FloorPlan.php` / `floor-plan.blade.php` link to `/admin/pos-page?table_id=…` (the POS component, i.e. the standard path) and `/admin/table-detail?table_id=…` (`TableDetail`, which can only confirm served and cancel; it cannot add items).

## 2. The standard path

`pos.blade.php` `checkout()` → `OrderSplitter::handle()`. The standard path **never adds to an existing order**. Every "Order" tap creates **new** order rows, split per destination. Then:
- **Bar (drinks):** deducted at creation, in `OrderSplitter::handle()` → `InventoryService::deductInventoryForOrderItems()` → `InventoryTransaction type=sale`.
- **Kitchen (food), `pending`:** deducted at Mark Ready since Phase 0D (`KitchenOrderService::markReady()`).
- **Kitchen created already paid (takeaway / full payment screen):** deducted at creation.
- **Attribution:** `user_id` is the PIN waiter, `shift_id` is that waiter's `currentShift()`, `kiosk_device_id` is the session device. `assertShiftsActive()` requires a waiter shift, plus a bartender shift for bar and (by setting) a chef shift for kitchen.
- **Notifications:** `OrderCreated` event, plus a "New Order" database notification to super_admin, chef, waiter and porter.

## 3. Comparison

| Question | Floor-plan add-item | Standard path |
|---|---|---|
| Creates an `InventoryTransaction` anywhere? | **At creation: no.** **Later:** only by accident. If the target is a still-`pending` kitchen order created after Phase 0D, Mark Ready deducts the *whole* order, added items included. Added to a **bar** order, to a kitchen order that has already deducted (legacy or already ready), or to a takeaway order: **never.** Mark Paid and the handover never create transactions. The handover count would just surface the missing stock as a custodian shortage | Yes (see section 2) |
| Waiter and shift attribution | Inherits the existing order's `user_id`/`shift_id`. There is **no shift check at all**: an off-shift user can add | Fresh attribution plus shift gates |
| Notifications | **None.** No `OrderCreated`, no kitchen/bar notification, and the order never re-appears as new on the bar/kitchen display | `OrderCreated` plus database notifications |
| Price | Current `products.price` / `menu_items.sale_price` (not client-supplied) | Same |
| Quantity bump on an existing line | Edits `order_items.quantity` in place, with no record | Never edits a line; creates a new order |

## 4. Other paths that add items without stock (out of scope, noted)

The admin `OrderResource` edit form (`Repeater::make('items')`) can also add or change order items with no stock effect. It is reachable from the UI. The first leak-sizing query below will catch its additions too.

## 5. Leak sizing (read-only, for the owner to run on production)

The local database is empty (0 for both queries). Production numbers are **not yet known**. Floor-plan rows carry no marker, so these are upper bounds:

```sql
-- A: lines created more than 5s after their order (no normal path does that;
--    OrderSplitter writes the lines in the same transaction). Catches the
--    floor-plan endpoint AND admin edit-form additions. Quantity bumps on
--    existing lines are invisible to this.
SELECT COUNT(*) AS items, MIN(oi.created_at) AS first_seen, MAX(oi.created_at) AS last_seen,
       COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS naira
FROM order_items oi JOIN orders o ON o.id = oi.order_id
WHERE o.is_return = 0 AND oi.created_at > o.created_at + INTERVAL 5 SECOND;

-- B: product lines on a deducted order with no matching 'sale' movement.
SELECT COUNT(*) AS items, COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS naira
FROM order_items oi JOIN orders o ON o.id = oi.order_id
WHERE o.is_return = 0 AND oi.item_type = 'product' AND o.stock_deducted_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM inventory_transactions t
                  WHERE t.reference = CONCAT('order:', o.id)
                    AND t.product_id = oi.product_id AND t.type = 'sale');
```

Because no screen ever called the endpoint, query A is expected to return only admin edit-form additions, if any.

## 6. Why the prompt's Step 2 fix doesn't map cleanly

Step 2 says to route add-item through "the same service entry point the standard path uses for adding items to an existing order". **No such entry point exists.** The standard path never adds to an existing order; it always creates new split orders through `OrderSplitter`. The two ways to fix this are:
- **(a)** make the endpoint create a new order at the target order's table through `OrderSplitter`, which changes what the endpoint does; or
- **(b)** remove the unused endpoint.

This decision was put to the owner; see section 7.

## 7. Decision (2026-10-01) and what was done

**Owner decision: remove the unused endpoint.** Nothing was rewired.

- Deleted `app/Http/Controllers/FloorPlanController.php` and its three routes in `routes/web.php`: `POST /admin/floor-plan/add-item`, `GET /admin/floor-plan/order/{orderId}` and `GET /admin/floor-plan/popular-items`. No screen used any of them. The Filament Floor Plan page itself (`/admin/floor-plan`) is unchanged and still links to the POS page.
- Corrected a comment in `app/Models/MenuItem.php` that named `FloorPlanController` as the kitchen-stock gate. The gate is `InventoryService`.
- Prompt tests 1–4 compared a rewired endpoint against the standard path. With the endpoint gone they have nothing to compare, so they were replaced by:
  - a test that all three URLs now return 404 and leave the order untouched;
  - a test that the Floor Plan page still links to the POS page.
- Prompt test 5 (architecture) became `tests/Feature/Architecture/OrderItemSingleCreatorTest.php`. Only `OrderSplitter` and the POS return ticket may create order lines anywhere in `app/` or the views.
- No backfill. Production leak sizing is the two queries in section 5, for the owner to run.
