# Phase 2: Verification Report (Step 1)

**Date:** 2026-10-01
**Scope:** Step 1 of the Phase 2 build prompt (Guest Menu, Table Sessions, Guest Requests, Cancel). This report is read-only.
**Verdict:** **Neither STOP condition applies.** There is one routing rule (`categories.type`), and the availability rule is reachable without any POS cache. Each point is detailed below, with the items flagged for Phase 3.

---

## 1. Drinks vs Food: one rule, `categories.type`

| Line | Where it goes | Code |
|---|---|---|
| Menu item | Always **kitchen** | `OrderSplitter::handle()` `groupBy` (line 66): `type === 'menu_item'` → `'kitchen'` |
| Product, category `drink` | **bar** | `OrderSplitter::getWarehouseForProduct()` → bar warehouse; then `=== getBarWarehouseId()` → `'bar'` (lines 74–77) |
| Product, category `food` | **kitchen** | Same, using the kitchen warehouse |
| Product, category `service` / other | **main** (warehouse 3). This is neither bar nor kitchen | Same |

The POS reads stock through the **public** `InventoryService::getWarehouseForProduct()`. That function has the same `match` on `categories.type` and the same bar/kitchen warehouse resolution: the first and second `consumer` warehouses. `OrderSplitter` keeps a private copy of that logic. The copy is identical apart from caching the warehouse IDs, so **the rule is one rule (the category's `type`) in two copies that agree**.

Phase 2 reuses the public `InventoryService` functions (`getWarehouseForProduct()`, `getBarWarehouseId()`, `getKitchenWarehouseId()`) and compares their results exactly as `OrderSplitter` does, so the guest tab always matches where the order will be routed. **Service-type products (station `main`) are left off the guest menu**, because they have no bar or kitchen to go to.

Edge case, for information: with only one consumer warehouse, the bar and the kitchen share an ID. `OrderSplitter` then routes food products to `bar`. The guest service mirrors this by using the same comparison.

## 2. Availability

| Item | What the POS does | Where |
|---|---|---|
| Menu item | Lists only `available_for_sale = true`. Its `available_stock` accessor only sorts sold-out dishes to the bottom; it doesn't hide them | `pos.blade.php` `with()` (line 1002) and `MenuItem::getAvailableStockAttribute()` |
| Product | Lists only `is_active = true`, with stock = `inventory_items.quantity` at `InventoryService::getWarehouseForProduct($product)`. You can only add it while that is above 0 | `pos.blade.php` `with()` (lines 975–995, **cached** 30 min) and `validateAndAddToCart()` (line 303, **uncached** direct query) |

**No STOP:** the rule is reachable without any POS cache. There is **no named, reusable method for product availability**; the POS repeats a three-line query inline. Phase 2 does not touch `pos.blade.php`. Instead, `App\Services\Guest\GuestMenuService` runs the same uncached query against the same public `InventoryService::getWarehouseForProduct()`. Per **D11**, menu items use `available_for_sale` only: kitchen food deducts at Mark Ready, and a shortage never blocks it, so ingredient stock isn't a sellability gate.

## 3. Money

| Column | Type | Unit |
|---|---|---|
| `menu_items.sale_price` | `decimal(10,2)` | naira |
| `products.price` | `decimal(10,2)` | naira |
| `order_items.unit_price`, `subtotal` | `decimal(10,2)` | naira |

The guest tables use `decimal(10,2)` naira as well.

## 4. Flagged for Phase 3: `OrderSplitter::handle()`

- **Per-line price (the price lock): NOT supported.** `handle()` deliberately **overwrites** every client price with the current `products.price` / `menu_items.sale_price` (the "never trust a client-supplied price" comment, lines 31–56). A confirmed request whose price changed after submit would be ordered at the **new** price unless Phase 3 adds an explicit, server-side price option.
- **`chips` / `note` per line: NOT supported.** `OrderItem::create()` (lines 140 and 158) writes only `order_id`, `product_id`/`menu_item_id`, `product_name`, `item_type`, `quantity`, `unit_price` and `subtotal`. The `order_items.chips` and `note` columns (Phase 1A) are never filled by any order path.

## 5. Table identity

`Table.name` (unique `varchar`, e.g. "Table 5") is the display field. There is **no sections table**; `tables.location` is free text that the kiosk picker groups by. Rooms display as `"Room {$room->number}"`.

## 6. Rate limiter and frontend stack

- **Limiter:** `guest-menu`, registered in `AppServiceProvider::boot()` and applied as `throttle:guest-menu` on the `/m/{token}` and `/menu` group in `routes/web.php`. Phase 2 replaces it with the D10 limiters.
- **Frontend:** Vite 7 with `laravel-vite-plugin` 2 and Tailwind 4 (`@tailwindcss/vite`). The existing entries are `resources/css/app.css`, `resources/js/app.js` and the two Filament theme CSS files. **Alpine was not an npm dependency**; until now it came only inside Livewire's bundle. Phase 2 adds `alpinejs` and `@fontsource/fraunces` (`fraunces-latin-600-normal.woff2`, 18 KB). Compiled assets are committed (`public/build`), so `npm run build` is part of the change.

## 7. `MenuAvailabilityService` authorization and role names

- **Today:** no authorization of its own. The KDS only requires a signed-in active cook (any `staff_pin` user).
- **Role names in use** (`ShieldSeeder`): kitchen = **`chef`**. There is **no `supervisor` role**: the codebase's supervisor check is `hasRole(['manager', 'admin', 'super_admin'])` (`ServedConfirmationService`, `PorterDeliveryService`). Part A (D13) therefore allows **`chef`, `manager`, `admin`, `super_admin`**.
