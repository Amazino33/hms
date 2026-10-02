# Guest QR Ordering: Codebase Audit (Tables + Rooms)

**Date:** 2026-09-30
**Scope:** Read-only audit of the existing HMS codebase against the locked Guest QR Ordering design.
**Method:** Source reading, `php artisan about`, `php artisan model:show` against the dev MySQL schema, grep across `app/`, `resources/`, `routes/`, `config/`, `database/migrations/`, `tests/`, `public/`.
**Nothing was modified.** This file is the only output.

---

## 1. Executive summary

1. The order pipeline has **one choke point**: `OrderSplitter::handle()`, which splits by bar or kitchen, enforces shifts, **deducts stock immediately**, fires events and notifications, and creates commission. A guest "request" must **not** go through it until a waiter accepts.
2. `orders.status` is an enum with no "requested" value. Many reports filter only on `status != 'cancelled'`, so a pending guest row in `orders` would count as revenue. **Guest requests need their own table.**
3. A `Table` entity exists (`tables`: name, capacity, status, location). There is no QR token/slug and no table session or tab concept. The "tab" is implied by "unpaid orders at `table_id`".
4. Drinks (and dine-in food) deduct stock **at order creation**. Room orders defer it until **Mark Ready**. Cancel only restocks when `stock_deducted_at` is set.
5. Payments: `order_payments` is **many rows per order**, and `payer_reference` already exists. But **no staff flow can record a partial payment against a table**. `markPaidFast` always pays the full outstanding amount, and the full modal **deletes and re-creates** the table's orders.
6. **No real-time stack.** `BROADCAST_CONNECTION=log`, there is no `config/broadcasting.php` and no Reverb/Pusher/Echo, and `OrderCreated` broadcasts into a log. Everything live is `wire:poll`. Filament database notifications poll every 20s, but only inside the admin panel.
7. **No web push.** The service worker has no `push` handler, and there are no VAPID keys, subscriptions table or package. Waiter phones only get toasts on the page they have open.
8. **No booking token.** It is explicitly documented as not existing (`BookingService`, `RoomOrderService`). A per-stay 4-digit code needs new columns.
9. **No food sold-out toggle on the staff side.** The only switch is `menu_items.available_for_sale` on the admin MenuItem form. Drink availability is derived from `inventory_items.quantity`.
10. There are **no item notes/modifiers**, **no product images or descriptions**, **no bank accounts setting**, **no public guest routes** and **no Mark Ready event** (only DB notifications).

---

## 2. Readiness table

| Area | Status | Note |
|---|---|---|
| A. Orders & lifecycle | **Partial** | Solid pipeline, but no source field, no "request" state, and `waiter-shift` is mandatory at creation |
| B. Stock deduction timing | **Exists** | Deduct at creation (dine-in) or at Mark Ready (room), with the `stock_deducted_at` guard |
| C. Tables | **Partial** | Entity exists, but has no QR token/slug, no session and no assigned-waiter field |
| D. Rooms, folios, token | **Partial** | Folio and zero-balance checkout exist. The booking token is **missing** |
| E. Products & availability | **Partial** | Flat categories with `type` food/drink/service. No image, description, modifiers or kitchen sold-out toggle |
| F. Payments & settlement | **Partial** | Multi-row payments and `payer_reference` exist. Partial or split table payment flow is **missing** |
| G. Kiosk surfaces | **Partial** | The waiter kiosk (`/kiosk`) exists. The "bar kiosk" is a Filament admin page, not a kiosk |
| H. Real-time & notifications | **Missing** | Polling only. No broadcasting, no push |
| I. KDS | **Partial** | `KitchenOrderService::markReady()` is shared, but fires no event |
| J. Public routes & security | **Partial** | Only device-registration, PWA and ZKTeco routes are public. No guest rate limiter |
| K. Settings | **Partial** | Key/value `settings` table plus a `companies` row. No bank accounts, no specials |
| L. Sales data | **Exists** | `order_items` joined to `orders`, or `inventory_transactions` with `type='sale'` |
| M. Tests | **Exists** | Pest, 238 files, domain folders, a no-bare-`abort()` arch test |

---

## 3. Explicit answers

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Can an order exist pending with no waiter or shift? | **Technically yes at the schema level, no in practice.** `orders.user_id` and `shift_id` are nullable. But `OrderSplitter::handle()` takes a non-null `int $userId` and `assertShiftsActive()` throws unless that user has an active waiter shift. Room orders skip only the waiter-shift check. Return tickets bypass the splitter entirely. | `app/Services/OrderSplitter.php` (`handle`, `assertShiftsActive`); `model:show Order` |
| 2 | Is there an order source/channel field? | **No.** The nearest fields are `destination` (bar/kitchen/main), `kiosk_device_id`, `booking_id`, `table_id` and `is_return`. `origin_label` is a derived accessor. | `app/Models/Order.php` (`getOriginLabelAttribute`) |
| 3 | Is there a Table entity? | **Yes.** `tables` has `id, name (unique), capacity, status enum(available, occupied, reserved, cleaning, maintenance), location`. | `app/Models/Table.php`; `database/migrations/2026_01_21_184611_create_tables_table.php` |
| 4 | When exactly do drinks deduct stock? | **At order creation**, inside the `OrderSplitter::handle()` transaction, via `InventoryService::deductInventoryForOrderItems()` → `deductProductInventory()`. This throws "Out of Stock" if bar stock is short. The exception is room orders (`defer_stock_deduction`), which deduct at `BarDisplay::markAsReady()`. | `app/Services/OrderSplitter.php`; `app/Services/InventoryService.php`; `app/Filament/Pages/BarDisplay.php` |
| 5 | Can one table/order carry multiple partial payments? | **Schema: yes. Flows: no.** `order_payments` is `hasMany` from `Order`. But `markPaidFast()` always pays each served order's full outstanding balance. `processPayment()` deletes the table's ready/served orders and re-creates them with one proportional payment. There is no "pay ₦X of this table now" action. | `app/Models/Order.php` (`payments`); `resources/views/livewire/pos.blade.php` (`markPaidFast`, `processPayment`) |
| 6 | Does the transfer reference column exist? | **Yes:** `order_payments.payer_reference` (nullable varchar), added in `2026_07_14_190400_add_verification_fields_to_order_payments_table.php`. It is displayed in `TransferQueue`, but **no code writes to it**. `folio_lines.reference` is the hotel equivalent, and it is written by `FolioService::recordPayment()`. | `app/Filament/Pages/TransferQueue.php`; grep across `app/` and `resources/` |
| 7 | What real-time mechanism exists? | **Polling only.** `wire:poll.10s` (POS header), `wire:poll.5s` (Bar/Kitchen Display), `wire:poll.{kds_poll_seconds}s` (KDS), and Filament `databaseNotificationsPolling('20s')` (admin panel only). `OrderCreated` implements `ShouldBroadcastNow`, but the driver is `log`. | `.env` / `.env.example`; `app/Events/OrderCreated.php`; `app/Providers/Filament/AdminPanelProvider.php` |
| 8 | Is web push possible today? | **No.** `public/sw.js` has only install/activate/fetch handlers. There are no VAPID env keys, no `push_subscriptions` table, and no `minishlink/web-push` or notification-channel package in `vendor/`. | `public/sw.js`; `composer.json`; `vendor/` listing |
| 9 | Does the booking token exist, and does it expire at checkout? | **No.** It is explicitly documented as absent: "There is no separate 'booking token'". Authorization is `Booking::currentlyCheckedIn()` for the room. | `app/Services/BookingService.php` (class docblock); `app/Services/RoomOrderService.php` (docblock); `app/Models/Booking.php` (`scopeCurrentlyCheckedIn`) |
| 10 | Is there a food availability toggle, and where? | **Only an admin form field:** `menu_items.available_for_sale`, a `Toggle` on the MenuItem Filament resource form. There is no one-tap sold-out toggle on the KDS, KitchenDisplay or kiosk. Separately, `MenuItem::available_stock` derives portions from recipe ingredients. It returns `null` (meaning unlimited) when there is no recipe or enforcement is off. | `app/Filament/Resources/MenuItems/MenuItemResource.php`; `app/Models/MenuItem.php` (`getAvailableStockAttribute`) |
| 11 | Do order items support notes/modifiers? | **No.** `order_items` has `product_id, menu_item_id, item_type, product_name, quantity, unit_price, subtotal, return_reason`. There are no notes, modifiers or JSON options. | `model:show OrderItem` |
| 12 | Does a Mark Ready event exist to listen to? | **No dispatched event.** Mark Ready is a status update plus `Notification::sendToDatabase()`, in two separate places: `KitchenOrderService::markReady()` (shared by `KitchenDisplay` and `/kds`) and `BarDisplay::markAsReady()` (bar, its own copy). The only hook is `OrderObserver::updated()` on `status` becoming `ready`. | `app/Services/KitchenOrderService.php`; `app/Filament/Pages/BarDisplay.php`; `app/Observers/OrderObserver.php` |

---

## 4. Detailed findings

### A. Orders & order lifecycle

**What exists**
- `app/Models/Order.php` (table `orders`). Columns: `id, order_number (unique), status enum('pending','preparing','ready','served','paid','cancelled','returned','partial'), stock_deducted_at, served_at, is_return, cancellation_reason, destination, total_amount, amount_paid, paid_cash, paid_pos, payment_method, table_id, user_id, shift_id, kiosk_device_id, booking_id, picked_up_by, picked_up_at, kds_picked_up_by, kds_picked_up_at, processed_by_user_id, guest_id, timestamps`. `LogsActivity` logs only status, cancellation, return and amounts.
- `app/Models/OrderItem.php` (table `order_items`). Columns are listed in Answer 11.
- `app/Observers/OrderObserver.php`, registered in `AppServiceProvider` via `Order::observe`:
  - `updating`: restocks on the transition to `cancelled` or `returned`.
  - `updated`: creates a `Commission` on the first transition to `paid`.
- `app/Services/OrderSplitter.php`: the single creation choke point.
- `app/Events/OrderCreated.php`: broadcasts on the `orders` channel (log driver).

**Entry points that create orders**

| Entry point | File | Path |
|---|---|---|
| Kiosk, staff phone and admin POS "Order" button | `resources/views/livewire/pos.blade.php` `checkout()` | `OrderSplitter` with status `pending` and the waiter's `currentShift()->id` |
| POS full payment modal | same file, `processPayment()` | **Deletes** the ready/served orders at the table (raw `inventory_items` increment, no `InventoryTransaction`), then re-creates them via `OrderSplitter` as `paid` or `partial` |
| Room order (receptionist) | `app/Filament/Pages/RoomOrder.php` → `app/Services/RoomOrderService.php` `placeOrder()` | `OrderSplitter` with `booking_id`, `defer_stock_deduction`, `pending`, plus a `FolioLine type=order` |
| Return ticket | `pos.blade.php` `submitReturnRequest()` | `Order::create` directly (`is_return=true`, `total_amount=0`) |
| Floor plan add-item | `app/Http/Controllers/FloorPlanController.php` `addItemToOrder()` | Adds `order_items` to an existing order and updates `total_amount`. **No stock deduction call was found in this path** (worth confirming separately) |

**Status transitions observed**
- `pending` → `ready`: `BarDisplay::markAsReady()` / `KitchenOrderService::markReady()`
- `ready` → `served`: `ServedConfirmationService::confirm()`, or `PorterDeliveryService::confirmDelivered()` for rooms
- `served` → `paid`: `pos.blade.php` `markPaidFast()`
- Created directly as `paid`/`partial`: `processPayment()` re-creation
- any → `paid`: `ShiftAccountingService::convertOrderToDebt()`
- active → `cancelled`: `pos.blade.php` `confirmCancelOrder()`, `TableDetail::cancelOrder()`
- return ticket `pending` → `returned` / `cancelled`: `ReturnConfirmationService`
- **`preparing` is never set anywhere.** It only appears in `whereIn` filters and badge maps.

**Waiter attribution:** `user_id` is the waiter (`auth()->id()` under the `staff_pin` guard, set in `pos.blade.php` `boot()`). `shift_id` is `auth()->user()->currentShift()?->id`. Table "ownership" is enforced softly: `updatedSelectedTableId()` denies a different waiter if the latest active order's `user_id` differs. `Table::latestActiveOrder()` shows the waiter's name on the kiosk grid.

**The void flow**
- `app/Filament/Pages/VoidOrderItem.php` → `app/Services/UnreturnableVoidService.php` `apply()`. It is PagePermission-gated ("Supervisor Only"). It reduces `order_items.quantity` and `subtotal`, recomputes `orders.total_amount`, and writes `unreturnable_voids`. **It never touches stock.**
- The physical return flow is: a return ticket, then bartender/chef confirmation (`ReturnConfirmationService`), which restocks via the observer.
- Whole-table cancel is `pos.blade.php` `confirmCancelOrder()` (requires a reason). The observer then restocks if `stock_deducted_at` is set.

**Gaps for this feature**
- There is no `source`/`channel` column, so guest-originated orders cannot be distinguished after acceptance.
- There is no pre-acceptance state that is safe to keep in `orders` (see Risks).
- There is no persistent "assigned waiter per table". It is inferred from the latest active order only.

**Risks and coupling**
- **Revenue leakage in reports.** `RevenueReportService`, `WaiterLedgerService`, `StaffReportService` and `PayrollCompilationService` count any order with `status != 'cancelled'` (or not in returned/cancelled). A `requested` order row would be counted.
- **`order_number` collision.** The format is `'ORD-'.time().'-'.{B|K|M}` with a unique index. Two accepts to the same destination within one second throw a `QueryException`, which surfaces as the generic "Could not send order". Guest traffic makes bursts more likely.
- **Shift gates at acceptance.** Bar lines need an active bartender shift. Kitchen lines need an active chef shift, unless `companies.require_chef_shift_for_kitchen_orders` is off. Acceptance can fail after the guest has already waited.
- `processPayment()` deletes and re-creates orders. Any guest-side reference to `order_id` (tracker, bill page, claims) **will dangle** after a full-modal payment.

### B. Stock deduction timing

**What exists**
- Drinks and all dine-in lines deduct in `OrderSplitter::handle()` → `InventoryService::deductInventoryForOrderItems()` → `deductProductInventory()` (row-locked, throws on shortfall, writes `InventoryTransaction type=sale` with `unit_cost_at_sale`) and `deductMenuItemIngredients()` (writes `IngredientTransaction type=usage`, and goes negative when enforcement is off). Both stamp `orders.stock_deducted_at`.
- **Kitchen recipe deduction at Mark Ready applies to room orders only.** `KitchenOrderService::markReady()` calls `deductInventoryForOrderItems()` only when `$order->booking_id` is set, and `BarDisplay::markAsReady()` does the same. **Dine-in kitchen orders deduct recipes at creation, not at Mark Ready.** This contradicts the premise in the audit prompt.
- Cancel: `OrderObserver::updating()` → `InventoryService::returnInventoryForCancelledOrder()`, which reads `stock_deducted_at` fresh from the DB and skips the restock if it is null.

**Gaps:** None for the "nothing touches stock before acceptance" rule, as long as requests never enter `orders`.

**Risks and coupling**
- If a request were stored as an `Order` row with deduction deferred, a later cancel would leave **no stock trace** (the null guard works). It would still leave an `orders` row counted by reports (see A) and send the `OrderSplitter` DB notifications.
- `processPayment()`'s raw `DB::table('inventory_items')->increment(...)` restock has no `InventoryTransaction`. This is an existing ledger gap outside this feature, noted because the bill page will sit next to it.

### C. Tables

**What exists:** `app/Models/Table.php`. Columns are listed in Answer 3. `orders.table_id` is a nullable FK (added in `2026_01_21_194907_add_table_id_to_orders_table.php`). Tables are identified by the unique `name`. `location` is free text and the only grouping.

**How it works:** The "tab" is every order at that `table_id` with status in `pending/preparing/ready/served`. `tables.status` is flipped to `occupied` by `checkout()` and back to `available` on pay or cancel.

**Gaps:** No unguessable public identifier for a QR code. Using the `id` or `name` in a URL is enumerable. There is no table-session or tab entity to scope a guest bill page, split state, or "Another round" history to one sitting. There is no assigned-waiter column.

**Risks:** `tables.status` is updated by several code paths without locking, so a guest-driven flow that also writes it would race with the POS.

### D. Rooms, folios & booking token

**What exists**
- `app/Models/Booking.php`: `status` (`reserved` / `checked_in` / `checked_out` / `cancelled` / `no_show`), `check_in`, `check_out`, `checked_in_at`, `checked_out_at`, `checkout_snapshot`, and more. `scopeCurrentlyCheckedIn()` means `checked_in` with today between `check_in` and `check_out` inclusive.
- `app/Models/Folio.php`: one per booking. `balance()` is the live `SUM(folio_lines.amount)`.
- `app/Models/FolioLine.php`: type enum `room_charge, order, incidental, adjustment, payment, discount`, plus `shift_id, payment_method, verified, reference, reversal_of_line_id`. Lines are immutable.
- Room charges post via `RoomOrderService::placeOrder()`, which writes one `FolioLine type=order` for the summed split orders **at creation**. `FolioService` handles incidentals, discounts and payments (transfers post `verified=false`).
- Checkout gate: `BookingService::checkOut()` throws if `$folio->balance() > 0.01`, then writes `checkout_snapshot`.

**Booking token:** It does not exist (Answer 9). A 4-digit per-stay code needs new nullable columns on `bookings`, for example a code hash and issued timestamp. It would be generated in `BookingService::checkIn()`. "Dies at checkout" can be enforced by resolving only through `currentlyCheckedIn()`, so there is no expiry job to maintain. A room change (`BookingService::changeRoom()`) keeps the same booking, so the code would follow the guest to the new room. The sticker for the old room would then stop resolving, which is the correct behaviour.

**Gaps**
- No code column.
- No brute-force protection design for a 10,000-value space per room. This needs per-room and per-IP throttling.
- **No cancel path for room orders was found.** Room orders have `table_id = null`, so the POS and TableDetail cancel paths (which key on `table_id`) cannot reach them. `FolioService::voidLine()` only allows `payment`/`discount`, so a folio `order` line cannot be reversed. A guest-cancel design must not create room orders before acceptance.

**Risks:** The folio line is posted at order creation, not at delivery. If room requests become orders on acceptance, the folio is charged immediately. That is fine, provided acceptance is the point of no return.

### E. Products, categories & availability

**What exists**
- `app/Models/Category.php`: **flat** (no `parent_id`). `type` is one of `food`, `drink`, `service` (see `CategoryResource`), plus `commission_rate`. Drinks versus Food is `categories.type`, which also drives warehouse routing (`InventoryService::getWarehouseForProduct()`).
- `app/Models/Product.php`: `name, sku, category_id, price, cost_price, last_cost_price, fridge_par, is_active, base_unit, pack fields`, and soft deletes. **There is no image or description column.** `pos.blade.php` reads `$item->product->image`, which is always null.
- `app/Models/MenuItem.php`: `name, sku, category_id, type, sale_price, available_for_sale`. No image or description.
- Drink availability is derived live: `pos.blade.php` `with()` / `validateAndAddToCart()` read `inventory_items.quantity` for the product at `InventoryService::getWarehouseForProduct()`. There is no named model method for it.
- Food availability: `MenuItem::available_stock` (portions from recipes), plus `available_for_sale`.

**Gaps:** Categories have no sub-categories, so the design's "subcategories" can only be the categories themselves. There are no photos, descriptions or modifier/notes storage, and no quick kitchen sold-out toggle. Adding one means building a real staff toggle, and it should write `available_for_sale` so there is a single source of truth.

**Risks:** The POS caches `categories` for 3600s and `products_{category}` for 1800s. A guest menu reusing those caches would show stale stock. Services lines (`type=service`) route to warehouse 3 ("main"), so they would create a `main`-destination order.

### F. Payments & settlement ⭐

**What exists**
- `app/Models/OrderPayment.php` (`order_payments`): `order_id, user_id, shift_id, amount, method (cash | pos | transfer | split), paid_at, verified, verified_by, verified_at, flagged, flag_reason, flagged_by, flagged_at, ruling, ruling_note, ruled_by, ruled_at, payer_reference`.
- It is `Order::payments()` hasMany, so **multiple payments per order are allowed by the schema**. There is no table-level or tab-level payment entity.

**Waiter mark-paid, end to end**
1. The order must be `served` (`ServedConfirmationService`). `markPaidFast()` blocks on any `pending/preparing/ready` order at that table.
2. `markPaidFast($method)` locks the served orders and writes **one `OrderPayment` per order for its full outstanding amount**, with `user_id` and `shift_id` set to the *acting* PIN user. It sets `amount_paid = total_amount` and `status = paid`. The observer then creates the commission.
3. Transfer rows sit unverified in the cashier queue (`app/Filament/Pages/TransferQueue.php` → `OrderPaymentVerificationService::verify()` / `flag()`).

**Settlement**
- `ShiftAccountingService::expectedCashRemittance()` = cash payments on the shift (and `order.paid_cash` for `split`) minus confirmed cash drops. `expectedPosMachineTotal()` excludes transfers.
- `CashierSettlementService`: the cashier enters counted cash and the machine total **blind** (it never reads `declared_cash`). The transfer channel is complete when no unverified, unruled transfer `OrderPayment` remains on the shift. A shortfall plus charged flags becomes a `StaffDebt`.
- Bar and kitchen are split per destination when the waiter served both (`usesChannelSplit()`).

**Transfer reference:** It exists, but nothing writes it (Answer 6).

**Where a 3-way transfer split breaks today**
1. **No UI or service records a partial amount.** `markPaidFast()` always pays the full outstanding balance of every served order. Three transfers can only be recorded as one `transfer` payment per order, not three.
2. **`processPayment()` cannot be used.** It supports only `cash` + `pos` split amounts (`$splitPayments['cash'|'pos']`), and it deletes and re-creates the orders, which destroys any earlier partial `OrderPayment` rows' parent orders.
3. **Payments attach to split destination orders, not the bill.** A guest "split by items" crosses bar and kitchen orders. Mapping three guest transfers onto N orders needs allocation logic that does not exist.
4. **The `partial` status is terminal-ish.** A `partial` order is excluded from `markPaidFast()`, which filters `status='served'`, so a second payment against it has no path.
5. **The transfer queue verifies per `OrderPayment` row.** Three rows with three `payer_reference`s would verify correctly, which is the good news. The gap is purely in creating them.

**Risks:** `OrderPayment.shift_id` and `user_id` are the actor's, so the rows land in that actor's settlement. A guest "claim" must never create an `OrderPayment`, or it would enter the blind transfer channel and could block `finalizeIfComplete()`.

### G. Kiosk surfaces

**What exists**
- **Waiter kiosk:** route `/kiosk` (`kiosk.device` middleware) → `resources/views/kiosk/idle.blade.php` → `livewire/kiosk-idle-screen.blade.php` (table grid plus PIN pad). After the PIN, the waiter goes to `/kiosk/order/{table}` (`staff_pin.auth`) → `livewire/kiosk-order-wrapper.blade.php` → `livewire/pos.blade.php`. The layout is `resources/views/layouts/kiosk.blade.php`. The staff-phone mirror is `/staff`, `/staff/order/{table}` (`trusted.device`).
- **PIN identity per action:** it is one login per interaction. `kiosk-order-wrapper` returns to the grid on `order-completed`, on `lock-requested`, or after 75s of inactivity (`INACTIVITY_TIMEOUT_SECONDS`). `PinAuthService::attempt($pin, $throttleKey)` returns the `User`. The KDS signs a cook in and keeps them signed in (`kds-board.blade.php` `submitPin` / `signOutCook`), and re-checks the guard on every write.
- **"Bar kiosk":** **it does not exist as a kiosk.** The bar queue is `app/Filament/Pages/BarDisplay.php` in the admin panel (`web` guard, PagePermission, `wire:poll.5s`). The kitchen has both `KitchenDisplay` (admin) and `/kds` (kiosk device).

**Refresh**
- `kiosk-idle-screen` has **no poll**, so the table grid and waiter names are stale until reload.
- POS: `wire:poll.10s="loadCurrentShift"`, which skips the render unless the shift changes.
- BarDisplay and KitchenDisplay: `wire:poll.5s`.
- KDS: `wire:poll.{kds_poll_seconds}s="refreshTiles"` (setting, default 5).
- Announcement board: no polling, loaded once.

**Best insertion points**
- **Waiter kiosk:** a new, separately polled Livewire component on `kiosk-idle-screen` (above the table grid). It is visible without a PIN, and the PIN pad on "Accept" can reuse the `submitPin` pattern. This avoids growing the 2,751-line `pos.blade.php`.
- **Bar:** a panel on `BarDisplay` (admin, `web` guard). Acceptance there would be by the logged-in admin user, not a waiter PIN. To keep "any waiter accepts with their PIN", either add a PIN-pad sub-component to BarDisplay (as the KDS does) or stand up a bar kiosk route mirroring `/kds`.

**Risks:** Any accept action must call `auth()->shouldUse('staff_pin')` in `boot()`. The production bug documented in `pos.blade.php` `boot()` shows that Livewire follow-up requests do not re-run `staff_pin.auth`.

### H. Real-time & notifications

**What exists**
- Broadcasting: `BROADCAST_CONNECTION=log` in `.env` and `.env.example`. There is **no `config/broadcasting.php` and no `routes/channels.php`**. No Reverb, Pusher, Soketi or laravel-echo is installed (`composer.json`, `package.json`, `vendor/laravel`).
- The shared notification helpers from the notification-reliability fix:
  - `app/Services/UserFeedback.php`: `blocked($title, $body)` (danger, persistent), `failed($title = 'Action failed', $body = …)` (danger, persistent) and `succeeded($title, ?$body)` (success, 4s).
  - `app/Support/LoggingNotification.php`: bound over Filament's `Notification` in `AppServiceProvider`. Every `->danger()` toast is also written to the System Error Log via `ErrorLogRecorder::recordNotification()`.
- Database notifications (`notifications` table): `sendToDatabase()` is used in `OrderSplitter` (new order, to super_admin/chef/waiter/porter), `KitchenOrderService`, `BarDisplay` (ready), `OrderPaymentVerificationService` (flag), `StaffDebtObserver`, `CountSessionService` and `StockTransferService`. They are read only in the **admin panel** bell (`databaseNotificationsPolling('20s')`, with a sound or badge script in `AdminPanelProvider` `BODY_END`).
- PWA: `public/sw.js` (cache `hms-v2.7`, no push), `public/manifest.json`, `app/Livewire/PwaInstall.php`.

**How waiter phones receive anything today:** The staff phone runs `/staff` with the kiosk layout. It gets Filament flash toasts for its own actions and the announcement board on page load. It does **not** see database notifications, because those live in the admin panel under the `web` guard, which PIN users do not use. There is no out-of-app alert of any kind, including for shift start and end.

**Gaps:** Every real-time requirement (guest request on the kiosk, waiter phone alert, periodic re-alert, guest status tracker) must be built on polling or on new infrastructure. Web push requires VAPID keys, a subscriptions table keyed to a user and device (a `TrustedDevice` is the natural anchor), a push library, and a `push` handler in `sw.js`, plus a `CACHE_NAME` bump.

**Risks:** Hosted over HTTPS is required for push and service workers. Confirm this on production (unverified). Re-alert timers need the scheduler and queue running. `deploy.sh` restarts the queue, but whether cron runs `schedule:run` in production is **unverified** (a comment in `routes/console.php` flags the same doubt).

### I. KDS

**What exists**
- `/kds` (in the `kiosk.device` group) → `resources/views/kds.blade.php` (extends `layouts.kiosk`) → `resources/views/livewire/kds-board.blade.php`. The board is viewable without a PIN, and actions need a `staff_pin` cook.
- `KitchenOrderService::markReady($orderId, $actorUserId)`: status goes `pending` → `ready` under a row lock, room orders deduct stock, and DB notifications are sent. `markPickedUp()` sets `kds_picked_up_*`.

**Event:** There is none (Answer 12). For the tracker (Sent → Accepted → Preparing → On the way), the data is all present: `status=pending` means sent or accepted, `ready` means prepared, and `kds_picked_up_at`, `served`, or `picked_up_at` for rooms means on the way. "Preparing" has no real state, because `preparing` is never set. A poll over these columns works with no new event. A dispatched domain event would require editing both `KitchenOrderService` and `BarDisplay`.

**Pattern to reuse for public routes:** The `/kds` shape is a thin route closure returning a view that extends a minimal layout and mounts one Volt component, with the gate done in middleware plus per-action checks inside the component. For guests, swap `kiosk.device` for a new guest middleware and throttle.

### J. Public routes & security

**Existing unauthenticated routes** (`routes/web.php`)
- ZKTeco `/iclock/*`: POST routes exempt from CSRF.
- PWA files (`/manifest.json`, `/site.webmanifest`, `/sw.js`): these use `withoutMiddleware(['auth','web'])`.
- `/browserconfig.xml`, `/offline.html`.
- `/__ops/reset-opcache/{token}` (APP_KEY-derived token).
- `/kiosk/register` GET, and POST with `throttle:10,1`.
- `/staff/login` GET, and POST with `throttle:login`.
- `/`, which redirects to `/admin`.

**Rate limiting:** Only `login` and `two-factor` (`FortifyServiceProvider`) are defined, plus inline `throttle:10,1`. `PinAuthService` has its own lockout keyed by device. There is no limiter for anonymous guest traffic.

**CSRF and sessions:** The standard `web` group (CSRF plus session). `SESSION_DRIVER=database`, lifetime 120 minutes, `same_site=lax`. A guest Livewire page gets a session and CSRF automatically. **Every scan creates a row in `sessions`**, which is a growth and cleanup concern at guest volume. `encryptCookies` exempts only the two device-token cookies.

**Domain/URL:** `APP_URL` is `http://localhost` locally. The production domain is not in the repo (**unverified**). QR codes would point at `{APP_URL}/g/t/{token}` and `/g/r/{token}` or similar. The paths must avoid `/kiosk`, `/staff`, `/kds`, `/admin` and `/ceo`.

**Risks:** `NoBareAbortTest` (`tests/Feature/Architecture/NoBareAbortTest.php`, and `tests/Unit/NoBareAbortTest.php`) scans `app/` only. A guest controller or component under `app/` must render a friendly page for unknown tables or expired codes, not `abort(404)`. A guest middleware under `app/Http/Middleware/` may `abort()`.

### K. Settings

**What exists**
- `app/Models/Setting.php` (`settings`: `key` unique, `value` text, `type`, `updated_by`), accessed only via `app/Services/SettingsService.php` (`get`, `getBool`, `set`, `setBool`). It is cached for 3600s and activity-logged.
- `app/Models/Company.php` (single row id=1: name, address, phone, logo, feature toggles, maintenance fields), edited in `app/Filament/Pages/ManageCompanySettings.php`.
- `config/hms.php` holds env-level values.

**Gaps:** There is nowhere to store **multiple bank/transfer accounts**. A JSON value in `settings` would work, but a small table is cleaner for ordering and active flags. There is no specials or banner field. `Announcement*` models are staff-targeted notices (targets, recipients, acknowledgements), not guest-facing, so they should not be reused.

### L. Sales data

**What exists**
- `order_items` (`product_id`, `menu_item_id`, `item_type`, `quantity`) joined to `orders` (`created_at`, `status`). This covers both drinks and food. `FloorPlanController::getPopularItems()` already runs this query for 30 days, products only.
- `inventory_transactions` (`product_id`, `type='sale'`, `quantity`, `created_at`) and `ingredient_transactions` (`type='usage'`). There are `type`+date indexes (`2026_07_17_100200_add_type_date_indexes_to_transactions_tables.php`), but they cover ingredients, not menu items.

**Recommendation:** Query `order_items` ⋈ `orders` where `orders.created_at >= now()-N hours`, excluding `cancelled`, `returned` and `is_return`, grouped by `item_type` and id, and cache it for a few minutes. Index coverage on `orders.created_at` is **unverified**. Check it with `SHOW INDEX FROM orders` before relying on it at volume.

### M. Tests

- Pest 3 style. `tests/Pest.php` extends `Tests\TestCase` with `RefreshDatabase` for `Feature`. sqlite `:memory:`, sync queue.
- There are 238 files across domain folders: `tests/Feature/{Pos, Kiosk, Kitchen, Hotel, Cashier, Accounting, Security, Architecture, …}`.
- File names are `{Subject}{Behaviour}Test.php` (e.g. `FastMarkPaidTest.php`, `KioskOrderAttributionTest.php`), and hotel steps are named `HotelStep5RoomOrderTest.php`. Tests use `it('…')` descriptions.
- Architecture: `NoBareAbortTest` (Feature and Unit). No `arch()` Pest arch-plugin tests were found.
- Relevant existing suites to keep green: `Pos/FastMarkPaidTest`, `Pos/CheckoutSplitTest`, `Pos/OrderSplitterPriceIntegrityTest`, `Kiosk/StaffPinGuardIsolationTest`, `Kitchen/KdsBoardTest`, `Hotel/HotelStep5RoomOrderTest`, `Hotel/HotelStep8CheckoutTest`.

---

## 5. Must-not-touch coupling report

| Protected module | Where the new feature would call or sit next to it |
|---|---|
| **InventoryTransaction pipeline** | Only reached through `OrderSplitter::handle()` → `InventoryService::deduct*` on acceptance, and through `OrderObserver::updating()` on cancel. The guest menu must read `inventory_items` only. It must never call `InventoryService` write methods. Watch the `processPayment()` raw restock, which the bill page sits beside. |
| **StaffDebt write paths** | `CashierSettlementService::recordDebtAndLog()`, `ShiftAccountingService::convertOrderToDebt()`, `CashierSessionService`, `CountSessionService`, `SettlementDetail`. Guest claims must not create `OrderPayment` rows. If they did, they would enter settlement and could become `charge`-ruled debt. |
| **Commission write paths** | `OrderSplitter::calculateCommission()` (orders created as `paid`) and `OrderObserver::calculateAndSaveCommission()` (on the first `paid`). Commission goes to `orders.user_id`. **Acceptance must set `user_id` to the accepting waiter**, or commission goes to the wrong person. |
| **Bartender handover count / dual-PIN seal** | `CountSessionService::sealAgreement()` and `BartenderChefShiftService`. `OrderSplitter::assertShiftsActive()` refuses bar orders without an active bartender shift, so acceptance during a handover gap will fail. The feature does not call the seal itself. |
| **Cashier blind confirmation** | `CashierSettlementService::transferChannelComplete()` / `finalizeIfComplete()` and `OrderPaymentVerificationService`. Only the waiter's real mark-paid should create a `transfer` `OrderPayment`. It may pass the guest's claimed reference into `payer_reference`, which nothing writes today. |
| **CEO read-only resources** | `app/Filament/Ceo/Resources/Orders/OrderResource.php` hard-codes the status option labels. A new `orders.status` value would need a label there. A separate requests table avoids touching it. |
| **DailyBusinessSnapshot job** | `hms:compute-daily-snapshot` (daily at 08:15) → `DailyMetricsService`. This reads `OrderPayment` (by `paid_at`), `FolioLine` payments and unverified transfers. Unaffected as long as claims are not `OrderPayment`s. |
| **Payroll module** | `PayrollCompilationService` sums `Commission` and filters orders not in returned/cancelled. It is affected only through commission attribution and any new order status. |

---

## 6. Recommended build order

1. **Foundation (no behaviour change).** Add migrations for:
   - a `guest_requests` table plus a `guest_request_items` table (with `notes` and modifier JSON);
   - a `tables.public_token` column;
   - `bookings` per-stay code columns;
   - a nullable `orders.source` column (default `staff`);
   - a nullable `orders.guest_request_id` column;
   - a `venue_bank_accounts` table.

   Keep requests **out of `orders`** so reports, the observer, commission and CEO labels stay untouched. Add a guest rate limiter and a guest middleware.
2. **Public read-only menu.** Add the `/g/t/{token}` and `/g/r/{token}` routes, reusing the `/kds` route-view-Volt pattern with a new lightweight dark layout. Show Drinks and Food by `categories.type`, availability from `inventory_items` plus `menu_items.available_for_sale`, and search. No writes yet.
3. **Staff sold-out toggle.** Add a one-tap `available_for_sale` flip on KDS/KitchenDisplay so food availability has a real staff source of truth.
4. **Guest submit and cancel.** The guest writes a `guest_request` only (with notes and chips). The guest can cancel while it is pending. There are zero stock, bill or ledger effects.
5. **Incoming queue on the waiter kiosk.** Add a separate polled component on `kiosk-idle-screen`, then the bar equivalent (a PIN-pad sub-component on `BarDisplay`, or a bar kiosk route).
6. **Accept with PIN.** Wrap the conversion in a service that calls `OrderSplitter::handle()` with the accepting waiter's `user_id` and `shift_id` and `source=guest_qr`. Map shift-gate exceptions to `UserFeedback::blocked()`, and fix the `order_number` collision risk here. For rooms, call `RoomOrderService::placeOrder()`. Record an assigned-waiter per table session so later requests auto-route.
7. **Status tracker.** Poll the request, then its orders (`status`, `kds_picked_up_at`, `served_at`, `picked_up_at`).
8. **Guest bill page (read-only first).** Show the live tab, item selection, equal or item split (computed client-side only) and the bank accounts list.
9. **"I've paid" claim.** Store it as a guest-side claim record (not an `OrderPayment`) that notifies the waiter. Then extend waiter mark-paid to accept a partial amount and `payer_reference`, which requires a new partial-payment path distinct from `processPayment()`. **This is the riskiest step.** Do it last among the money work, with dedicated settlement tests.
10. **Alerts.** Start with polling and sound on kiosks and phones. Then add web push as its own increment (VAPID keys, subscriptions keyed to `TrustedDevice`, an `sw.js` push handler, and a cache version bump) and scheduled re-alerts. Confirm cron and HTTPS on production first.
11. **v1 extras.** Add "Another round", "Popular tonight" (cached `order_items` query), call-waiter reasons, and the specials banner (a `settings` key).
