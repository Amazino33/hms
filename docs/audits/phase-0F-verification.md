# Phase 0F: Stock Integrity Verification Report (Step 1)

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 0F build prompt. Read-only, except for a throwaway probe test that was deleted afterwards.
**Verdict: STOP (Part A).** The full payment screen does **not** deduct ingredients twice. Its real defect is worse, and it lives **inside the delete-and-recreate logic itself**, so it can't be fixed without changing that behaviour, which this prompt puts out of scope. Parts B–E are independent and were not started either, pending the owner's decision (section 7).

---

## 1. `processPayment()`: what really happens (proved with a probe test)

`resources/views/livewire/pos.blade.php`:

| Step | Line(s) | What it does |
|---|---|---|
| 1 | `updatedSelectedTableId()`, ~153–163 | Builds `$this->existingItems` from every active order at the table. **The key is `$item->product_id ?: $item->id`**, so a menu-item line is keyed by its **order-item id**. |
| 2 | `processPayment()`, ~466–486 | For dine-in, loads the table's `ready`/`served` orders. For each **product** line it raw-increments `inventory_items` (`DB::table(...)->increment`, **no `InventoryTransaction`**); menu-item lines are skipped because `product_id` is null. Then it **deletes the items and the orders**. There is **no DB transaction** around this. |
| 3 | ~488–523 | Rebuilds the cart from `$this->existingItems` and calls `OrderSplitter::handle()` with status `paid`/`partial`. |
| 4 | `OrderSplitter::handle()`, line 37 | Decides menu item versus product **only from the key** (`str_starts_with($key, 'menu_')`). An order-item-id key is therefore treated as a **product id**. |

**Root cause (one paragraph):** the payment screen rebuilds the table's bill from `existingItems`, whose keys don't carry the item type. `OrderSplitter` then reads every menu-item line as `Product::find(<order-item id>)`. Two outcomes follow, both observed in the probe:

- **A. No product has that id** (the normal production case, since order-item ids run far past product ids). `OrderSplitter` throws "Product not found: Jollof". The payment fails, but **the table's served orders were already deleted** and their product stock raw-restocked. The bill disappears, **no payment is recorded**, and drinks that really left the bar are put back on the shelf without any stock record. The waiter's shift no longer shows the money as outstanding.
- **B. A product happens to have that id.** The dish is re-created **as that product** (e.g. "Jollof" sold as Beer at ₦1,000), so that product's stock is deducted for a dish. The order's `amount_paid` is the wrong product's price. In the probe the waiter took ₦13,500 cash but the payment row recorded ₦3,000. Because `existingItems` is keyed by mixed id spaces, **a menu item and a product can also collide on the same key and merge into one line**: the probe's "Jollof × 1" and "Beer × 2" became "Jollof × 3", billed at ₦13,500.

**Ingredients are never deducted twice**, because a menu item never gets back to being a menu item. **Products, however, are double-counted in the ledger** on every successful payment through this screen. The original `sale` transaction stays (referencing a deleted order), the stock is put back with no transaction, and the re-created order writes a second `sale`. Shelf quantity ends up right, but stock-trace and cost reports read two sales.

A correct fix has to change how the delete-and-recreate rebuilds the order: keys that carry the type, one DB transaction, and no raw restock. **That is the STOP condition.**

## 2. Damage sizing (read-only SQL, for the owner to run on production)

```sql
-- A. Product sales whose order no longer exists. Every successful full-screen
--    table payment leaves its originals' 'sale' rows behind like this
--    (admin Orders "Delete" does too). This is the double-counted ledger volume.
SELECT COUNT(*) AS rows_, COUNT(DISTINCT reference) AS deleted_orders,
       MIN(created_at) AS first_seen, MAX(created_at) AS last_seen, SUM(quantity) AS units
FROM inventory_transactions t
WHERE t.type = 'sale' AND t.reference LIKE 'order:%'
  AND NOT EXISTS (SELECT 1 FROM orders o WHERE CONCAT('order:', o.id) = t.reference);

-- B. Lines re-created as the WRONG product (outcome B). Upper bound: a product
--    renamed since the sale also shows up here.
SELECT COUNT(*) AS lines_, MIN(oi.created_at) AS first_seen, MAX(oi.created_at) AS last_seen,
       SUM(oi.quantity) AS units, SUM(oi.subtotal) AS naira_recorded
FROM order_items oi JOIN products p ON p.id = oi.product_id
WHERE oi.item_type = 'product' AND oi.product_name <> p.name;

-- C. Order deletions with no order created in the next 10 seconds: the shape of a
--    failed full-screen payment that deleted the bill (outcome A). Approximate.
SELECT COUNT(*) AS suspected_lost_bills, MIN(d.created_at), MAX(d.created_at)
FROM activity_log d
WHERE d.log_name = 'order' AND d.event = 'deleted'
  AND NOT EXISTS (SELECT 1 FROM activity_log c
                  WHERE c.log_name = 'order' AND c.event = 'created'
                    AND c.created_at BETWEEN d.created_at AND d.created_at + INTERVAL 10 SECOND);
```

No backfill was made.

## 3. Cooked-food returns

- **Path:** `pos.blade.php submitReturnRequest()` creates a return ticket (its own `Order`, `is_return = true`, `total_amount = 0`). Then `ReturnConfirmationService::confirm()` (bar or kitchen display) runs `reduceOriginalOrder()` (the bill adjustment) and sets the ticket to `returned`. That fires `OrderObserver::updating()` → `InventoryService::returnInventoryForCancelledOrder()`, whose `is_return` branch **always restocks**: `returnMenuItemIngredients()` puts the dish's recipe ingredients back.
- **`kitchen_waste_logs` is never used for returns.** The Phase 0D "cooked" branch only fires on `cancelled`, never on `returned`.
- **A second problem, from Phase 0D timing:** a return can be raised against a line on a **still-pending** kitchen order (`existingItems` includes pending orders). That order hasn't deducted yet, but the return ticket still restocks the ingredients, which **inflates** kitchen stock. Mark Ready later deducts only the reduced quantity.
- No other return path exists.

## 4. Admin Orders edit form

`app/Filament/Resources/Orders/OrderResource.php`, edit page `EditOrder` (Shield `Update:Order`, super_admin only in the seeder):

| Field | State today |
|---|---|
| `total_amount` | Read-only, but **recomputed** client-side from the item repeater and dehydrated |
| `table_id` | `disabled()->dehydrated()`: not editable in the UI, but sent back on save |
| `status` | `disabled()->dehydrated()`: **not editable in the UI**, but a tampered Livewire request could change it; no server-side guard |
| `cancellation_reason` | Shown and required only when status is `cancelled` |
| `user_id` (server) | `disabled()->dehydrated()` |
| `items` (Repeater, relationship) | **Fully editable:** change product, change quantity, add or delete lines. No stock movement and no `InventoryTransaction`. Product-only (menu-item lines have no `product_id` and fail its `required` rule) |
| Header `DeleteAction` | **Deletes the order** with no stock movement either |

So "set status to ready from the form" is **not possible in the UI** (the prompt's premise), only through a tampered request. Item editing and delete **are** live.

**Every place in `app/` that sets status `ready`:** `KitchenOrderService::markReady()` (kitchen) and `BarDisplay::markAsReady()` (bar only, scoped to `destination = 'bar'`). Nothing else. A server-side "force ready" doesn't exist and isn't needed.

## 5. `StorekeeperTransferFormSubmitTest`

It fails asserting the view contains `document.addEventListener('submit', function (e) {`. The view has had `document.addEventListener('submit', async function (e) {` since commit `ce6acf9` (2026-09-11, "Gate transfer receiving on shift…"), which made the handler async on purpose. **The cause is in the test, not the app.** The fix is one line in the test's expected string.

## 6. `deploy.sh`

Shown in full in the session. Relevant facts:
- **Step 4** runs `composer install --no-dev --optimize-autoloader`. That triggers `post-autoload-dump` → `filament:upgrade`, which overwrites the hand-patched `public/js/filament/notifications/notifications.js` on every deploy.
- **No front-end build anywhere.** The header comment says *"no npm needed — compiled assets are committed to git, since this server can't run npm"*.
- `git pull --ff-only` will **refuse** to pull a commit that touches `notifications.js` while the server's working copy of it is modified (by the step above).

## 7. Owner decisions (2026-10-01) and what was built

| Question | Decision |
|---|---|
| The full payment screen bug (STOP) | **Surgical fix now.** Keep delete-and-recreate (its full audit stays separate), but make it correct and atomic |
| Parts B–E | **Build them**, including the not-yet-cooked return finding and locking the admin edit page's Delete |

**Part A (`pos.blade.php`):**
- `updatedSelectedTableId()` keys menu items `menu_{id}`, the same key the cart and `OrderSplitter` use. Dishes stay dishes and can't merge with a product.
- `processPayment()` runs the delete, re-create and payment rows in **one transaction**, so a failure leaves the bill untouched. The silent raw shelf restock is **removed**. The re-created orders are built with the existing `defer_stock_deduction` option and carry their originals' `stock_deducted_at`, so stock moves exactly once and the ledger gets no second `sale`.
- A served original that somehow never had its stock taken (only possible through the old admin bypass) is charged once, just before it's re-created.
- Lines voided to 0 are no longer resurrected as quantity 1.
- Takeaway: `processPayment()` can't create takeaway orders (it refuses unsent cart lines), so that path is unchanged and pinned at `OrderSplitter` level.

**Part B:**
- `ReturnConfirmationService` records the **cooked** portion of a confirmed kitchen return as `kitchen_waste_logs` (same shape as `OrderObserver`). `OrderObserver` never restocks a kitchen return ticket.
- **Also fixed:** a **rejected** return ticket (the item never came back) no longer restocks, bar included. It always did before, despite the existing test's title promising otherwise. Confirmed bar returns restock exactly as before.

**Part C:**
- The Orders edit form shows status, lines, total, table and server read-only, and none of them are written back.
- `EditOrder::beforeSave()` refuses a tampered change to status, lines or total with "Use Mark Ready, Void or Return — orders can't be edited directly."
- The edit page has no Delete button.
- The repeater's Product field is no longer `required()`. That rule had made every order containing food impossible to save.
- **Left editable:** only `cancellation_reason`, which is shown for cancelled orders only.
- **Not changed, still open:** the Orders *list's* row Delete and bulk Delete. `order_items` cascades on delete, so deleting an unpaid order silently takes its lines and bill with it.

**Part D:** the stale assertion in `StorekeeperTransferFormSubmitTest` now expects the intentional `async function (e)`.

**Part E (`deploy.sh`):**
- Before `git pull`, resets `public/build` and `notifications.js` to the committed versions, so the pull is never blocked.
- Restores `notifications.js` after `composer install`.
- Runs `npm ci && npm run build` after migrations if `npm` exists; otherwise prints a loud warning.
- `docs/deploy-node.md` explains installing Node LTS.
