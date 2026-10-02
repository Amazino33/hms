# Phase 0E: "Split by Method" Verification Report

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 0E build prompt. This is a read-only trace of how payment amounts are read before mixed-method payments exist.
**Verdict:** **Clear to build.** Waiter settlement, cashier blind confirmation and the DailyBusinessSnapshot all read `order_payments` row by row by `method`. None of them reads a single method per order.

> Note: the build prompt refers to "Decision D5" in `docs/design/guest-qr-ordering.md`. That file has no Decisions section and no D5, so nothing in this report relies on it.

---

## 1. The fast Mark Paid flow, end to end

There is **no service layer** for fast Mark Paid today. All of it lives in the POS Volt component.

| Layer | Location |
|---|---|
| UI, desktop admin POS (`hidden lg:block`) | `resources/views/livewire/pos.blade.php`: the "Mark Paid" block with three `wire:click="markPaidFast('cash'\|'pos'\|'transfer')"` buttons |
| UI, mobile and staff phone (`lg:hidden`) | same file, a second identical "Mark Paid" block |
| UI, kiosk touchscreen (`@if($isKioskDevice)`) | same file, cart footer "STATE 2" (`cartCount === 0 && existingCount > 0`): three `h-16` buttons, plus a "Split payment…" link that opens the **full** payment modal (`processPayment`) |
| Server | `pos.blade.php` `markPaidFast(string $method)` |
| Models | `Order` (`update(['amount_paid' => total_amount, 'status' => 'paid'])`), `OrderPayment::create`, `Table::update(['status' => 'available'])` |
| Side effects | `OrderObserver::updated()`: commission on the first `paid`. `DashboardCache::clearForOrder()` |

`markPaidFast()` steps:
1. Requires `auth()->user()->currentShift()`.
2. Uses the method whitelist `['cash', 'pos', 'transfer']`. Anything else returns silently.
3. Requires a real table (not takeaway).
4. Refuses if any order at the table is `pending/preparing/ready`.
5. Loads the `served` orders at the table. If there are none, it shows "Nothing to Pay".
6. Opens a DB transaction and re-fetches those ids with `status = 'served'` and `lockForUpdate()`.
7. For each order, `outstanding = round(max(0, total_amount − amount_paid), 2)`. If it is above 0, it creates one `OrderPayment` (`user_id`/`shift_id` = the acting PIN user and their current shift, `paid_at = now()`). It then sets `amount_paid = total_amount` and `status = 'paid'`.
8. Sets the table to `available` and resets the component state, then dispatches `order-completed`.

## 2. Payment methods and money storage

- **Methods:** there is no enum and no constants. `order_payments.method` is a `varchar(255)`. Values in use:
  - `cash`, `pos` and `transfer`, from `markPaidFast` and `processPayment`.
  - `split`, from `processPayment`'s cash+POS split. It is one row holding the combined amount, and the cash/POS breakdown lives only on `orders.paid_cash` / `paid_pos`.
- **`order_payments` columns:** `id, order_id, user_id, shift_id (nullable), amount decimal(10,2), method, paid_at, verified, verified_by, verified_at, flagged, flag_reason, flagged_by, flagged_at, ruling, ruling_note, ruled_by, ruled_at, payer_reference (nullable varchar 255), timestamps`.
- **Money storage:** naira as `decimal(10,2)`, both on `order_payments.amount` and on `orders.total_amount / amount_paid / paid_cash / paid_pos`. There are no kobo integers anywhere.
- **Outstanding** is computed as `total_amount − amount_paid` (the order's own column), not as a sum of `order_payments`. Existing tests seed `amount_paid` with no payment rows (`tests/Feature/Pos/FastMarkPaidTest.php`, "only charges the remaining outstanding balance…"). The new code keeps this formula so the single-method behaviour is identical. In live data the two agree: every path that sets `amount_paid` also writes an equal `OrderPayment` (`processPayment`, `markPaidFast`). The one exception is `ShiftAccountingService::convertOrderToDebt()`, but it sets `status = 'paid'`, so those orders never reach fast Mark Paid.

## 3. Critical consumers: row-based or single value per order?

| Consumer | How it reads payments | Verdict |
|---|---|---|
| **Waiter shift settlement (expected cash)**: `ShiftAccountingService::expectedCashRemittance()`, `expectedPosTotal()`, `expectedPosMachineTotal()`, `expectedCashForDestination()`, `rawAmountByDestination()` | `shiftPayments()` = `OrderPayment::where('shift_id', …)`, iterated **row by row**, matching on `$payment->method`. Destination comes from `$payment->order->destination`, so two rows on one order both land in that order's destination. The **only** per-order read is the `'split'` branch (`$payment->order->paid_cash` / `paid_pos`), which applies only to rows whose method is `split`. Phase 0E never writes `split` rows. | **Row-based ✅** |
| **Cashier blind confirmation**: `CashierSettlementService` (`expectedCash`, `expectedPosMachine`, `confirmChannelForDestination`, `transferChannelComplete`, `finalizeIfComplete`, `chargedFlagTotal`) | Delegates the expected figures to `ShiftAccountingService` (above). `transferChannelComplete()` checks `OrderPayment where shift_id, method='transfer', verified=false, ruling null`, so it works **per row**. Each transfer row is verified individually through `OrderPaymentVerificationService` / `TransferQueue`. It never reads `shifts.declared_cash`. | **Row-based ✅** |
| **DailyBusinessSnapshot**: `hms:compute-daily-snapshot` → `Ceo\DailyMetricsService` | `cashCollected()` = `OrderPayment::whereBetween('paid_at', …)`, **row by row** by `method` (transfer verified/unverified, pos, everything else into cash). `unverifiedTransfersAsOf()` is per row. `unsettledShiftAmountAsOf()` reads `shifts.declared_cash + declared_pos`. For waiter shifts those fields are **the waiter's own typed declaration**: `App\Livewire\ShiftManager::confirmShiftEnd()` overwrites them right after `User::endShift()`. So no per-order payment value reaches the snapshot. | **Row-based ✅** |
| **Sales/revenue reports**: `Ceo\RevenueReportService` | `lineItems()` reads `order_items.subtotal` (revenue, not payments). The payments breakdown (around line 259) reads `OrderPayment` **rows** with `method`. | **Row-based ✅** |
| **Commission**: `OrderObserver::calculateAndSaveCommission()`, `OrderSplitter::calculateCommission()` | Per **item quantity × category rate**, triggered once on the order's first transition to `paid`. It does not read payments at all. A split payment still causes exactly one `paid` transition per order. | **Unaffected ✅** |

Other row-based readers that were also checked: `Ceo\WaiterLedgerService` (`groupBy('method')`), `Ceo\ExposureService`, `Ceo\Pages\DailyDigest`, `StaffReportService`, `MyShiftReport`, `SupervisorDashboard`, `SettlementDetail::transferSummary()`, `TransferQueue` (shows `payer_reference`), and `SettlementFlagRulingService`.

## 4. Other code that assumes one payment (or one method) per order

None of these blocks the build. All of them are **already inaccurate for any order paid through fast Mark Paid today**, because `markPaidFast()` has never set `orders.payment_method`, `paid_cash` or `paid_pos`. Split by method does not make them worse.

| Location | Assumption | Impact |
|---|---|---|
| `orders.payment_method` (set to `'cash'` by `checkout()` and never updated by fast Mark Paid) and `app/Filament/Ceo/Resources/Orders/OrderResource.php` (`TextColumn::make('payment_method')`) | One method per order | CEO order list mislabels fast-paid orders. Display only |
| `app/Filament/Pages/DailyReport.php` (around lines 65–71), "cash flow" | `SUM(orders.paid_cash)`, `SUM(orders.paid_pos)` | Already excludes every fast-paid order. The staff-performance section on the same page is row-based |
| `app/Filament/Resources/ShiftManagement/Schemas/ShiftManagementInfolist.php` `getSystemExpectedTotals()` | `SUM(orders.paid_cash / paid_pos)` per waiter shift | Legacy "system expected" against the old `supervisor_confirmed_*` figures. Already wrong for fast-paid orders. **Not** the cashier settlement engine |
| `app/Models/User.php` `endShift()` | Seeds `declared_cash/pos` from `SUM(orders.paid_cash/paid_pos)` | Immediately overwritten by `ShiftManager::confirmShiftEnd()` with the waiter's typed figures |
| `ShiftAccountingService` `'split'` branches | A `split` row's cash/POS breakdown lives on the order | Only for `method = 'split'`. Phase 0E writes only `cash` / `pos` / `transfer` rows |
| `pos.blade.php` `processPayment()` (the full payment screen) | Deletes and re-creates orders, one proportional payment per order | Out of scope (separate audit). Kept untouched |
| `markPaidFast()` double submit | The second call finds no `served` orders and reports "Paid: ₦0" with nothing written | Becomes an explicit rejection in Phase 0E (test 9). This is the only deliberate behaviour change |

## 5. Decision

Step 2 may proceed. One adjustment to the prompt as written: the "fast Mark Paid component" is `pos.blade.php`, which also contains the full payment screen (`processPayment()`). That method deletes orders by design, and it is out of scope. So the architecture test (prompt test 12) checks **the new service class and the `markPaidFast` / `markPaidSplit` method bodies**, not the whole component file.
