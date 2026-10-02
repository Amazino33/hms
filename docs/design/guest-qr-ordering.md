# Guest QR Ordering — Locked Design (v1)

> Put this file in the repo at `docs/design/guest-qr-ordering.md`.
> Every Guest QR Ordering build prompt refers to it. If a prompt and this file disagree, STOP and ask.

## 1. Core principles
- **QR codes name a place. They never give authority.** Authority comes from staff confirmation.
- **A guest can only REQUEST. Only staff COMMIT.** Nothing touches stock, bills, reports or waiter ledgers until staff act.
- **Guest requests live in their own tables** (`guest_requests`, `guest_request_items`), never in `orders`. A real order is created through the normal `OrderSplitter` path only at the moment described below.
- **The system is the order.** WhatsApp chat, calls and claims never change an order. Staff change it in the system, with a reason.
- **The guest pays the price they saw** (the price is snapshotted when the request is submitted).

## 2. QR codes
- Every table and every room has its own random, unguessable token: `/m/{token}`. Never `/table/5`.
- One general **menu-only** link (`/menu`) for the entrance, posters and WhatsApp status. Browse only, no ordering.
- Admin QR page: download per table/room, **Print all** to PDF, **Regenerate** (the old token dies immediately and the change is logged).
- Print formats: table cards A6 (4 per A4), room stickers 80×80 mm (6 per A4), menu-only poster A5.

## 3. Guest menu
- Opens directly on scan. No app, no login. The header shows "Table 5" or "Room 7".
- Two tabs: **Drinks | Food**. Subcategories come from existing product categories.
- Photos, descriptions, search, "Popular tonight" (from real sales), specials banner, dark theme, fast on weak 3G.
- Sold-out food is hidden using the existing manual availability toggle (one source of truth).
- **The kitchen and bar are always available to order from.** There's no "closed" state on the guest menu.
- Chips: drinks get Cold / Not cold; food gets quick chips (e.g. Extra pepper, No onions). No price impact.

## 4. Table flow
1. The guest scans and orders → a **request** is created.
2. The request alerts the **waiter kiosk only** (repeating sound plus a flashing banner). It waits until a waiter accepts; waiters are re-alerted every 2 minutes.
3. **Any waiter accepts with their PIN** → becomes the table's waiter. Later requests from that table auto-route to them.
4. On acceptance:
   - **Food lines** → become a real order immediately → kitchen screen (KDS) as normal.
   - **Drink lines** → go to the bar queue on the bar display (still a request).
5. **The bartender releases the drinks** → the real drink order is created under the waiter's `shift_id` → stock deducts.
   - The bartender may only **reduce or remove** lines, with a required reason. The guest sees the change.
   - The release is credited to the one open bartender shift automatically (there's always exactly one).
6. The waiter delivers. Food follows the normal KDS → Mark Ready flow.

## 5. Room flow
1. The guest scans the room sticker. Ordering only works while the room is **checked in**; otherwise it's menu only. **No room code.**
2. On submit, the request is saved to the system **first**, then WhatsApp opens on the guest's phone with a pre-filled message to the reception WhatsApp number:
   ```
   🛎 Room 7 order · Ref R7-0423
   1x Jollof Rice  ₦4,500
   2x Malta (cold)  ₦2,000
   Total: ₦6,500
   ```
   Fallback button: **Order without WhatsApp**, which shows as "No WhatsApp: call the room."
3. Reception sees it on the reception screen (matched by Ref) and on WhatsApp, confirms with the guest, then taps **Approve**. Reception may paste the **guest WhatsApp number** (saved to the stay) and tick **Agreed to receive specials**.
   - The screen flags the **first order from each new phone** for that stay.
4. After approval: food → KDS, drinks → bar queue → bartender releases. The charge posts to the **folio**, never to a waiter ledger.
5. Reception picks the **porter** (from a porter list) → the guest sees "On the way with [name]". The porter outcome is **Delivered** or **Refused**. Refused means: unopened drinks are restocked, cooked food becomes a waste event, and the folio charge is reversed.

## 6. Live bill (guest phone)
- Line statuses: Waiting for confirmation → Confirmed → Preparing / At the bar → On the way → Delivered (rooms).
- **The total counts a line once it has become a real order** (food at confirmation, drinks at bartender release). Pending lines show greyed out.
- Tables: the bill for the current table session. Rooms: the folio charges for the current stay.

## 7. Payment
- The bill page lists **all transfer accounts** (bank, account name, number) with copy buttons.
- **"I've paid by transfer"** creates a `guest_payment_claim` (payer name, amount). **It is a claim, never a payment.**
- Tables: the waiter checks the claims add up → does the normal full **Mark Paid** → cashier blind confirmation as normal. Claim names go into `order_payments.payer_reference`.
- Rooms: reception handles the claims against the folio. Checkout gate as normal.
- **Split bill = a calculator only** (equal 2/3/4 ways, or by items). It records nothing.

## 8. Cancel rules
- The guest can cancel until a waiter or reception confirms.
- Confirmed but not yet released: staff can cancel for free (no stock or charge yet).
- After release / once it's a real order: normal void only.

## 9. Table sessions
- The first scan of a table opens a session. Later scans of the same table join it while it's open.
- **Move table:** the assigned waiter moves the open session to an empty table. The old table frees up, guests re-scan the new table's QR, and the move is logged. Moving onto a table that has its own open session is **blocked** in v1 (merge comes later).
- **Close table:** the waiter closes it after payment → the session ends → the next scan starts fresh.

## 10. Bartender has not started their shift
The bar is always orderable, but releasing requires an open bartender shift (enforced server-side). So:
- Drink requests still queue at the bar.
- The bar display shows a red banner: **"N guest orders waiting — start your shift to release them."**
- The waiter kiosk shows a "Bar shift not started" chip.
- If drink requests wait more than 5 minutes with no bar shift open → alert the manager/supervisor in `/admin`.
- Log every such wait (for the owner's report on late shift starts).

## 11. Other rules
- **Queue timers** on the waiter, reception and bar queues: green under 5 min, amber 5–10 min, red over 10 min.
- **Bartender handover:** unreleased bar requests carry over to the next bartender. The seal must neither block on them nor lose them.
- **Accepting a table means owning its money.** Guest-ordered items sit on the waiter's ledger like any other order.
- **No staff personal WhatsApp numbers** are ever shown to guests. Use **Call waiter** chips instead (Ice, Cups, Bill, Other).
- **"Another round"** repeats the guest's last drinks request as a new request (it still goes through confirmation).

## 12. Alerts in v1
Kiosk sound plus flashing banners, polling every 5–10 s. No phone push, no Reverb in v1.

## 13. Not in v1
Partial payments, a whole-table shared tab with per-phone items, percentage split, phone push / real-time broadcasting, WhatsApp Business API, merging tables.

## 14. Build phases
- **0A** order number generator · **0B** floor-plan stock leak · **0C** room order cancel and folio reversal · **0D** food deducts at Mark Ready
- **1A** menu content: photos, descriptions, chips, KDS sold-out toggle
- **1B** settings and QR codes
- **2** guest menu, table sessions, guest requests, cancel
- **3** waiter acceptance, bar queue and release, alerts, bar-shift nudge
- **4** live bill, status tracker, claims, split calculator, Another round, Call waiter, Move/Close table
- **5** rooms: WhatsApp handoff, reception approval, porters, Delivered/Refused
- **6** staff training sheets and a launch dry run

## 15. Decisions (from the code check, 2026-10-01)
These supersede any conflicting text above.

### D1 — More than one bartender shift open (supersedes §4.5 "the one open bartender shift")
- A release requires at least one active (non-stale) bartender shift, enforced server-side.
- Exactly one active → the release is credited to it automatically.
- More than one active → the bar display asks "Who is releasing?" with one button per active bartender (no PIN). It also shows a warning banner, and managers get an /admin alert: "2 bartender shifts open — end the old one."
- Do NOT add single-shift enforcement to BartenderChefShiftService in this feature.

### D2 — What "credited" means
- "Credited" = WHO released it, stored on the request side only:
  `guest_request_items.released_by_user_id`, `guest_request_items.released_at`.
- No bartender shift link on orders. `orders.shift_id` stays the waiter's. Do not repurpose `processed_by_user_id`.
- Bar accountability stays with the handover count (stock deducts from the bar location exactly as today).

### D3 — The waiter's shift ends while drinks are waiting
- A waiter cannot end their shift while they hold confirmed, unreleased guest requests. The end-shift screen lists them and offers **Hand over**: another on-shift waiter enters their PIN and takes over those requests and the table session. Log it in the append-only `guest_session_handovers` table.
- Safety net: if, at release, the assigned waiter has no active shift (stale or force-closed), the release is refused. The request goes back to the waiter queue as "Needs a waiter" and any waiter re-accepts it. The bar display shows "Returned to waiters."
- Food lines that already became orders at acceptance are unaffected.
- Room requests do not go through a waiter. They use the existing room-order creation path; Phase 5 confirms which identity and shift that path requires.

### D4 — Move table
- A move is one transaction: update the table number on all UNPAID orders in the session, on all pending/confirmed requests, and on the session itself. Paid orders are never touched.
- The destination must have no unpaid orders and no open session (no merging in v1).
- Allowed for: the session's assigned waiter (PIN) or a manager.
- Log it in the append-only `table_moves` table: session_id, from_table, to_table, order_ids (json), moved_by, created_at.
- Changing the table number on unpaid orders is a location correction, not a financial change. It is the only edit to existing orders that this feature allows, and it is always logged.

### D5 — Paying guest tables
- v1: orders linked to a guest table session can only be paid with the **fast Mark Paid** button. The full payment screen refuses them server-side and hides the option, showing: "Guest QR table — use Mark Paid."
- Fast Mark Paid gets an optional `payer_reference` input, pre-filled from that session's claims (payer names joined), written to `order_payments.payer_reference`.
- The full payment screen's delete-and-recreate behaviour is logged as a separate immutability issue for its own audit. It is NOT fixed in this feature.

### D6 — Guest tracker statuses
- Food "Preparing" = the order is on the KDS and not yet Mark Ready. No new order status.
- Drinks "At the bar" = confirmed but not yet released.

### D7 — Room folio charges
- Food posts to the folio at reception approval; drinks post at bartender release. So one room request can create two folio charges.
- Both charge descriptions carry the request Ref (e.g. "R7-0423") so they read as one order.
- Refused reverses each charge independently (using the 0C reversal).

### D8 — Phase 0D
- Stays its own phase with its own tests. It changes kitchen stock timing for ALL dine-in orders. Before deploying, tell the storekeeper and kitchen that ingredient counts now move at Mark Ready, not at order time.

### D9 — Guest pages are stateless
Guest routes run WITHOUT Laravel sessions or CSRF middleware (no `sessions` rows from guests). Each phone is identified by a random device cookie `selum_gd` (32 chars, httpOnly, Secure, SameSite=Lax, 30 days). Writes are protected by: the device cookie (SameSite=Lax blocks cross-site POSTs), a JSON-only content type, rate limits, and staff confirmation (a request is harmless until confirmed). Guest pages are Blade + Alpine + JSON endpoints, NOT Livewire (lighter on 3G, no session dependency).

### D10 — Guest rate limits (replace the old 60/min per IP)
Keyed by device cookie, with a generous per-IP ceiling because guests share venue Wi-Fi:
- Menu page GET: 60/min per device; 1,200/min per IP
- Status/availability polling: 30/min per device
- Submit request: 6 per 10 min per device; 40 per hour per table/room token
- Cancel: 20/min per device

### D11 — Menu freshness
The guest menu never reuses the POS product caches. Menu structure (categories, items, prices, photos, chips) is cached 5 min and invalidated on save of any menu item, product, category or chip. Availability is computed separately, cached 30 s, and polled by the page every 60 s. Availability rule = the same rule the POS uses (menu items: `available_for_sale`; products: their existing stock rule). There is one source of truth; reuse it, don't reimplement it.

### D12 — Payer reference vs payment method (for Phase 4)
Claim names pre-fill `payer_reference` on transfer lines only. If a table with transfer claims is paid with no transfer line, Mark Paid shows a warning ("Guest claimed ₦X by transfer — no transfer line entered") but does not block. The claims stay on the session marked "unmatched".

### D13 — Who may mark food sold out
`MenuAvailabilityService` accepts toggles only from users with a kitchen role, a supervisor or a manager. This is enforced server-side, even though anyone with a PIN can sign in to /kds.

### D14 — Stale requests and sessions
A pending (unconfirmed) request expires after 3 hours (status `expired`). A table session with no live requests and no activity for 3 hours auto-closes. Table sessions open on the FIRST SUBMITTED REQUEST, never on a scan, so scans never create ghost sessions.

### D15 — Who may accept a table's later requests
Once a table session has an assigned waiter with an ACTIVE shift, only that waiter may accept its new requests. Other waiters see them greyed out ("Emeka's table"). If the assigned waiter has no active shift, the request is open to every waiter (and whoever accepts becomes the assigned waiter).

### D16 — Kiosk sound needs one tap
Browsers block sound until someone taps the page. The waiter kiosk and the bar display show a "Tap to enable order sounds" bar until sound is enabled, and a visible "Sound off" warning whenever it's blocked.

### D17 — Full payment screen refuses guest tables (enforces D5)
`processPayment()` refuses, server-side, any table with an unpaid order linked to a guest request (via `guest_request_items.order_id`) and shows "Guest QR table — use Mark Paid." The option is hidden in the UI for those tables.

### D18 — Price lock reaches the order
Orders created from guest requests use `unit_price_snapshot`, never the current price. Only the guest services may pass a price override into OrderSplitter.

### D19 — What the guest's live bill contains
A table session has `bill_from_at` (default = opened_at). The live bill = the orders on the session's table (table identity as used by the POS) created at or after `bill_from_at`, while the session is open, PLUS greyed-out guest request lines not yet ordered.
On the FIRST acceptance of a session, if the table already has unpaid orders older than `opened_at`, the waiter is asked "This table already has ₦X unpaid. Same guests?"
- Yes → `bill_from_at` = the oldest of those orders' created_at.
- No → unchanged (the old orders must be settled separately).

### D20 — Closing a table
Closing is a manual waiter action, never automatic after payment (bars often pay per round). Close is refused while the session has unpaid bill orders or lines in pending / at_bar / needs_waiter. D14's auto-close NEVER closes a session with unpaid bill orders.

### D21 — Another round loads the cart
"Another round" copies this phone's last released drinks (final quantities and chips) INTO THE CART for review. It never sends automatically. Unavailable items are skipped, with a notice.

### D22 — Call waiter
Reasons: Ice, Cups, Bill, Other (text, max 60). It goes to the assigned waiter's kiosk strip (or to all waiters if none is assigned). Acknowledging is a single tap, with no PIN. Calls auto-expire after 15 min.

### D23 — Claims are append-only
`guest_payment_claims` rows are never edited or deleted. A guest may withdraw their own claim (status `withdrawn`). The status is set once more at payment: `matched` (a transfer line was used) or `unmatched` (D12).

### D24 — Room drinks: stock and charge at release
Room orders normally deduct at Mark Ready. For guest room DRINKS, the bartender's Release creates the room order AND runs the existing Mark Ready entry point in the same transaction, so stock and the folio charge happen at release (matching D7). Room FOOD follows the normal room path: the order is created at reception approval, and Mark Ready on the KDS deducts.

### D25 — Who can see a room's bill
A room's live bill (its folio lines for the current stay) is shown ONLY on "trusted" phones: devices that have had at least one request APPROVED by reception for this stay. Other phones see the menu, plus "Your bill appears after your first order is approved." This stops anyone with a photo of the sticker from seeing a guest's spending.

### D26 — Refused deliveries
A porter's "Refused" creates a refusal record; nothing is reversed immediately.
- Drinks: the bartender must confirm on the bar display that the bottles came back ("Returned ✓") before a manager can approve.
- Food: a manager approves (cooked food → waste, no restock).
Manager approval calls `RoomOrderService::cancel()` (existing rules: after Mark Ready = manager only), which reverses the folio charge through `FolioService::reverseOrderCharge()`. The guest bill shows "Refused — being reviewed" until it's decided.

### D27 — Guest contacts
On approval, reception may save the guest's WhatsApp number (from the chat) and tick "Agreed to receive specials". It is stored in `guest_contacts` (one row per stay + phone). Only managers can view or export the list, and only opted-in contacts are exported for marketing.

### D28 — Rooms call reception, not a waiter
In rooms, "Call waiter" becomes "Call reception" (Ice · Cups · Cutlery · Other) and goes to the reception Room Orders page.

### D29 — Reception edits before approval
Before approving, reception may only REDUCE or REMOVE lines, with a reason (same presets as the bar). Additions are made by the guest from the menu (a new request) or through the normal staff room-order flow. Never by editing the request.

### D30 — Guest brand theme
Dark charcoal ground, white text, the venue's deep red as the only accent. Red never signals an error (errors are amber with an icon). The logo is the original crest shown on a white circular medallion (it is drawn for white backgrounds). The venue name always comes from the system's company name setting, never typed into templates.

### D31 — Thumb-first layout
Everything a guest taps often lives in the bottom third: the cart bar, category pills, and a bottom nav of Drinks · Food · Search · Bill · Waiter (rooms: Reception). Sheets open from the bottom; the phone's back button and a swipe down close them.

### D32 — Splash
A short welcome splash on the first open of a visit: crest → "Welcome to {company name}" → "Table 5" / "Room 7". It never delays the menu: it shows for at least 1.2 s, leaves as soon as the menu is ready, and is never longer than 2 s. Tapping skips it. It's shown once per token per 4 hours.

### D33 — One tap: Mark Ready (supersedes "Release" wording everywhere)
On the bar display, guest drink cards sit IN THE SAME QUEUE as normal orders (oldest first) and use the same **Mark Ready** button. For a guest card, Mark Ready, in ONE transaction: creates the real bar order (OrderSplitter for tables / the room path for rooms, exactly as release did) AND marks it ready through the shared bar ready service. All release rules still apply (D1 bartender shift and "who is marking ready", D2 credit, D3 waiter safety net, D18 price lock, D24 rooms). Staff never see the word "Release".
