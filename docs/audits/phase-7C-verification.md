# Phase 7C — Step 1 verification

Read-only check before Guest Menu v2. Date: 2026-10-02.

**Result: no STOP. Every payload change in 7C is an added key. The 7A JSON snapshot is kept as the "before" shape, and a test proves it is a strict subset of the new one.**

## 1. Payloads and the cart

- **Page payload** (`#guest-boot`, built by `GuestMenuController::place()` / `bootData()`):
  - `menu.tabs.{drinks,food}[] = {name, slug, items[]}`. Each item is `{key, type, id, name, description, price, thumb, large, chips[], note}`. It comes from `GuestMenuService::payload()`, which is cached for 5 minutes and dropped whenever a menu item, product, category or chip is saved.
  - `unavailable` (keys, cached 30 s), `popular` (keys), `specials` `{text, image_url}`.
  - `token`, `kind`, `place`, `mode`, `notice`, `table` (state), `accounts`, `whatsapp`.
  - View variables: `venue`, `logo`, `splashLogo`.
- **Bill endpoint** (`GET /m/{token}/bill`): `{ok, table, bill, message}`. `bill` is `{totals, tracker, sections, claims, call, live}` (`GuestBillService::forSession` / `forStay`). It is `null` with no open sitting or for an untrusted room phone.
- **Requests endpoint:** `{ok, requests[]}`, with a per-line `status_label` / `final` and a per-request `live`.
- **Cart:** client only, in `localStorage['gq_cart_{token}']`. Each line is `{uid, key, type, id, name, price, qty, chips[], note}`. Identical key, chips and note merge into one line.

## 2. Admin forms

- **`MenuItemResource::form()`:** flat fields. `MenuContentFields::photo()` / `description()` sit at the top level (1A). Table filters: `MenuContentFields::missingPhotoFilter()`.
- **`ProductResource::form()`:** a **"Guest menu"** section holding the same two 1A fields. Table: `Products/Tables/ProductsTable.php` (trashed and missing-photo filters).
- **No sort-order column** on `menu_items` or `products`. Lists sort by name (`GuestMenuService::buildPayload()`).

## 3. `GuestRequestService::submit()`

`prepareLines()` validates each line:
- `type` and `id` must be on the menu;
- `qty` 1–20;
- availability (`isAvailableNow`);
- chips must belong to the category's active groups;
- note: tags stripped, 100 characters at most.

It returns the snapshot rows that `GuestRequestItem::create()` writes. **A per-line `added_via` goes in the same place:** validated in `prepareLines()`, then written with the other snapshot fields. It affects nothing else.

## 4. Ready drink lines and their time

- **Ready lines:** a released (marked-ready, D33) drink line has `status = released` and `released_at`. The bill and requests payloads show its label ("Ready") but **not the time**.
- **The round itself:** `GuestRoundService::lastRound()` already finds this phone's latest request with released drink lines (for D21), with final quantities and chips.
- **For the "Another round?" nudge:** `last_round` is new, with `ready_at` = the latest `released_at`.

## 5. Device cookie and earlier visits

- **The cookie:** `selum_gd`, 32 characters, httpOnly, Secure, SameSite=Lax, **30 days** (`EnsureGuestDevice`).
- **Earlier visits:** `guest_requests.device_id` is indexed (`device_id, status`). Requests from earlier sittings stay linked to `guest_table_sessions` (whose `closed_at` is set) or to a checked-out stay, so a phone's previous closed-sitting lines can be found by device id. "Order again" uses only this phone's own requests.

## 6. Guest Ordering Settings storage

- Single values are key/value rows through `SettingsService` (cached, activity-logged), wrapped by `GuestOrderingSettings`:
  - reception WhatsApp;
  - specials text, image, active, start and end.
- Lists live in their own tables (transfer accounts).
- **Plan:** the new settings follow the key/value pattern. Quick add-ons are stored as a JSON list of item keys in one setting.

## 7. Snapshot risk (STOP check)

`tests/Feature/Guest/snapshots/guest-json-shapes.json` pins the exact shapes of the guest JSON endpoints. 7C adds keys only:
- **Bill response, top level:** `latest_status`, `accepted_by_first_name`, `last_round`.
- **Page boot:** new keys, which are not in that snapshot.

No existing key is removed, renamed or retyped, so this is not a STOP. The old file is kept as `guest-json-shapes-7a.json`, and the test checks it is contained in the new shapes.
