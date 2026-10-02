# Phase 4 — Step 1 verification

Read-only check of the code before building the live bill, claims, split, another round, call waiter, and move/close table. Date: 2026-10-01.

**Result: no conflict with the design. No existing table move feature exists, so the build goes ahead.**

---

## 1. Fast Mark Paid UI

`FastMarkPaidService::payWithMethods()` has exactly one caller: the private `settleFastPay()` in `resources/views/livewire/pos.blade.php`. It is reached by three public methods:

| Method | Called from |
|---|---|
| `markPaidFast(string $method)` | the Cash / POS / Transfer buttons in all three POS layouts: desktop sidebar, phone sheet, and kiosk (the "STATE 2 — Outstanding" block) |
| `markPaidSplit(array $lines)` | `resources/views/partials/split-by-method.blade.php`, included three times (`desk`, `mob`, `kiosk`) |
| `fastPayOutstanding()` | the same partial; it pre-reads the total when the split panel opens |

How the unpaid orders are collected (identical in both methods):

```php
Order::where('table_id', $tableId)->whereIn('status', ['pending','preparing','ready'])->exists() // → refused "Not Ready"
$servedOrders = Order::where('table_id', $tableId)->where('status', 'served')->get();
```

In other words, **every** served order on the table, with no time window. `payWithMethods()` re-reads those ids under lock and requires each one to be `served`. On success, `settleFastPay()` sets `tables.status = 'available'` without conditions.

The waiter kiosk has no Mark Paid of its own. The kiosk idle screen (`kiosk-idle-screen.blade.php`) asks for a PIN on a table and opens this same POS component in its kiosk layout.

**Plan:**
- Leave `FastMarkPaidService` untouched.
- `GuestTablePaymentService::pay()` passes it only the session's unpaid **bill** orders (D19).
- `markPaidFast` / `markPaidSplit` / `fastPayOutstanding` route through the wrapper when the selected table has an open guest session with unpaid bill orders. Otherwise they behave exactly as before. Older orders that the waiter answered "No, different" to (D19) are still paid by the existing path once the guest bill is settled.
- `processPayment()` is not touched (the D17 guard stays).

## 2. Table identity on orders, and the "occupied" state

- **Identity:** `orders.table_id` (FK to `tables`), written in only three places:
  - `OrderSplitter` (line 160) — the POS and guest orders;
  - `pos.blade.php` — return tickets, at creation;
  - `GuestRequestService` — `guest_requests.table_id`, not orders.
- The admin Orders form shows `table_id` disabled since 0F. **Nothing ever changes it after creation.**
- **Occupied state** is computed in two different ways that live side by side:
  1. **The stored flag `tables.status`:**
     - set to `occupied` by pos `checkout()` (l.695);
     - set to `available` by pos `processPayment()` (l.621), `settleFastPay()` (l.871), `confirmCancelOrder()` (l.951), `FloorPlan::clearTable()`, `TableDetail::cancelOrder()`, `TablesTable` row/bulk actions, and `hms:clear-test-data`.
  2. **Derived from orders:**
     - `pos getTablesProperty()` eager-loads orders in `pending/preparing/ready/served`;
     - the table grids in pos (l.1433, 1716, 2212) show "occupied" only when `status === 'occupied' && hasActiveOrder`;
     - `Table::latestActiveOrder()` (`whereNotIn status ['paid','cancelled']`) drives the kiosk idle grid and `printTableBill`;
     - FloorPlan / floor-plan.blade.php use the same flag plus orders;
     - `dashboard-stats` counts only the flag.
- **Plan:** `TableMoveService` moves `orders.table_id` and keeps the stored flag honest. The destination becomes `occupied`; the source becomes `available` once nothing unpaid is left on it.
  - "Free table" for the destination picker = no unpaid order (`pending/preparing/ready/served`, not a return ticket) and no open guest session.

## 3. Existing table move/transfer features

**None.** No route, component, page or action moves orders between tables:
- searched `move.?table`, `transfer.?table`, `change.?table`, `switchTable`, `moveOrder`, `reassignTable`, `merge.?table`, and every write to `table_id`;
- `StockTransfer` is warehouse stock, unrelated;
- the 0F lock on the admin order form already says "moving a table is its own flow".

**No STOP needed.**

## 4. Voided and returned items

- **Unreturnable void** (`UnreturnableVoidService`): reduces `order_items.quantity` and `subtotal` in place and **keeps the row even at quantity 0**. The voided part is recorded in `unreturnable_voids`, and `orders.total_amount` is recomputed.
- **Return** (`ReturnConfirmationService::reduceOriginalOrder`): reduces the original line, or **deletes the row** when everything was returned, and recomputes the total. The return ticket is a separate order with `is_return = true`, `total_amount = 0`, and the same `table_id`.
- **Cancelled order:** `orders.status = 'cancelled'`, and its lines stay.
- **No soft deletes** on orders or order_items.

**Plan for the live bill:**
- return tickets (`is_return`) are excluded;
- quantity-0 lines and lines on a cancelled order show struck through as **"Removed by staff"**;
- partly voided or returned lines show their current (reduced) quantity;
- fully returned lines no longer exist, so they don't appear.

## 5. Logo

- **Uploaded and edited at:** `ManageCompanySettings` (`FileUpload::make('logo_path')->directory('company-logos')`), saved by `Company::updateOrCreate(['id' => 1], …)` in `save()`.
- **Stored on:** Filament's default disk, which follows `FILESYSTEM_DISK=local`, so the file sits in **private** `storage/app/private/company-logos/…` and is not web-reachable.
- **Read by:** `QrPrintSheets::logoDataUri()`, inlined as a data URI. `FloorPlan` builds an `asset('storage/'…)` URL, which is broken on the local disk; that is pre-existing and left alone.
- **Plan:**
  - a `Company` saved-hook (when `logo_path` changes) plus `branding:publish-logo` write a ≤ 400 px WebP to `public/media/branding/logo.webp`;
  - the encoding reuses the 1A processor's encoder;
  - the private original is never exposed.

## 6. D14 auto-close (`guest:expire-stale`)

`GuestRequestService::expireStale()` closes, with one mass `update`, every open session where both of these hold:
- `last_activity_at ≤ now − 3 h`;
- no request is `pending` or `confirmed`.

It does **not** look at orders at all, so a table with an unpaid bill but quiet requests would close. **Plan (D20):** skip any session whose bill has an unpaid order. The close itself moves into `TableCloseService`, so only one class ever writes a session's `closed_at`.

## 7. Phase 2 guest UI files

| File | What it holds |
|---|---|
| `resources/views/guest/menu.blade.php` | One page; the menu is embedded as JSON (`#guest-boot`). Header: venue name, place pill, and a "My orders" icon button (opens the `orders` sheet, with a badge). Specials, search, Drinks/Food tabs and pills, popular row, item list, cart bar, then sheets `item` / `cart` / `sent` / `orders`, plus a toast. |
| `resources/js/guest.js` | Alpine `guestMenu`: localStorage cart per token (`gq_cart_<token>`), availability poll every 60 s, requests poll every 8 s while any request is `live`. |
| `resources/css/guest.css` | Dark theme tokens (`--accent` gold `#E0B35A`), sheets, cart bar fixed at the bottom. |
| `resources/views/guest/layout.blade.php` | The error/invalid-code shell. |

**Plan:**
- the header's "My orders" button goes, replaced by the logo and a bottom nav (Menu | Bill | Call waiter);
- the cart bar sits above the nav;
- the orders list moves into the Bill tab.

## Gaps and notes found along the way

- **Return tickets block the fast path:** the existing POS "Not Ready" check counts a `pending` return ticket on the table, so it blocks fast Mark Paid until the bar or kitchen confirms the return. This is pre-existing and unchanged. The guest wrapper ignores return tickets when choosing which orders to pay; the existing check still applies before it.
- **Paid orders after a move:** after a move, the destination table may hold earlier, already-paid orders from other guests. The bill therefore follows the session's own moves (from `table_moves`) rather than "every order on the current table since `bill_from_at`". The bill shows:
  - each table's orders for the time the session sat there;
  - plus the orders that were moved.
