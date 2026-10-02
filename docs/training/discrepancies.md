# Guest ordering — Where the built app differs from the design

Found while writing the training sheets (Phase 6, 2026-10-02). Nothing here has been fixed; this phase is documents only.
"Design" means `docs/design/guest-qr-ordering.md`. Where a §15 decision overrides older text, the decision is treated as the design.

| # | Area | What the design says | What the code does | Where |
|---|---|---|---|---|
| 1 | Waiter re-alert (§4.2) | Waiters are re-alerted every 2 minutes. | A soft chime every 20 s while an order waits, plus a louder chime every 2 min once the oldest order is over 2 min old. | `resources/views/livewire/guest-orders-strip.blade.php:287`, `:291` |
| 2 | End-shift hand-over (D3) | The end-shift screen lists the waiting tables and offers **Hand over**. | The list only appears after the waiter taps **End Shift** and it fails with "Error ending shift: …". There is no list beforehand. | `app/Livewire/ShiftManager.php:252`, `resources/views/livewire/shift-manager.blade.php:387` |
| 3 | Payer name on fast Mark Paid (D5, §7) | Fast Mark Paid gets a payer-reference box, pre-filled from claims. | Only the **Split by method** panel has the box (pre-filled). The one-tap **Transfer** button records no payer name. | `resources/views/livewire/pos.blade.php:1640`, `:1957`; `resources/views/partials/split-by-method.blade.php` |
| 4 | Call waiter routing (D22) | A call goes to the assigned waiter's strip, or to all waiters if none. | On a shared kiosk every waiter sees every call; the assigned waiter's are only highlighted. Filtering happens on personal phones only. | `resources/views/livewire/guest-orders-strip.blade.php:236` |
| 5 | Who may move / close a table (D4, D20) | The assigned waiter (PIN) or a manager. | Also any waiter on shift when no waiter has accepted the table yet. | `app/Services/Guest/TableMoveService.php:135` |
| 6 | Sold-out panel (D13) | Kitchen role, a supervisor or a manager. | Allowed roles are chef, manager, admin, super_admin. A custom "supervisor" role (used on some installs) is refused. | `app/Services/MenuAvailabilityService.php:22` |
| 7 | No-bartender alert (§10) | Alerts go to the manager/supervisor. | Alerts go to manager, admin and super_admin only — not "supervisor". | `app/Services/Guest/GuestBarMonitor.php:98` |
| 8 | Deciding refusals (D26) | "A manager approves." | Manager, admin or super_admin; a "supervisor" cannot. The Delivery Refusals page is seeded for super_admin and manager only. | `app/Services/Guest/RoomDeliveryService.php:31`, `database/seeders/ShieldSeeder.php` |
| 9 | Late bar-shift log (§10) | Every wait with no bar shift is logged for the owner's report. | It is logged, but no screen or report shows it. The manager's "daily check" needs a developer query for now. | `app/Services/Guest/GuestBarMonitor.php:50` (no page reads `bar_shift_wait_logs`) |
| 10 | Who records Delivered / Refused (§5.5, D26) | "The porter outcome" / "a porter's Refused". | Reception taps **Delivered** / **Refused** on Room Orders; porters do not use the system for guest orders (owner decision 2026-10-02, phase-5-verification.md). | `resources/views/filament/pages/room-orders.blade.php:165`, `:166` |
| 11 | Refusing part of a delivery (D7) | "Refused reverses each charge independently." | A refusal must cover every item of that order together; part of one order can't be refused. Each order (food / drinks) still reverses on its own. | `app/Services/Guest/RoomDeliveryService.php:139` |
| 12 | Room ordering window (§5.1) | Ordering works while the room is checked in. | Also requires today to be within the booking's check-in → check-out dates. A guest kept past the check-out date (not yet checked out) sees "Ordering is available during your stay." | `app/Models/Booking.php:119` |
| 13 | Split calculator (§7) | Equal 2/3/4 ways, or by items. | Equal split is 2–10 people. It is hidden completely for rooms (Phase 5 prompt). | `resources/views/guest/menu.blade.php:429`, `:166` |
| 14 | Guest status words (§6) | "Waiting for confirmation". | "Waiting for waiter" (tables) or "Waiting for reception" (rooms); confirmed shows "Confirmed by Emeka" / "Confirmed by reception". | `app/Models/GuestRequest.php:37`, `:58`; `app/Http/Controllers/GuestMenuController.php:411` |
| 15 | WhatsApp text and badge (§5.2) | Chips in lower case, e.g. "(cold)"; badge "No WhatsApp: call the room." | Chips are shown as written on the menu ("(Cold)") and a note gets its own "Note:" line; the badge reads "No WhatsApp — call the room". | `app/Services/Guest/GuestWhatsapp.php:40`, `resources/views/filament/pages/room-orders.blade.php:94` |
| 16 | Unmatched claims (D12) | Claims stay on the session marked "unmatched". | Stored correctly, but no screen lists unmatched claims across tables and rooms. They show only inside one request on Guest Requests → View. | `app/Filament/Resources/GuestRequests/GuestRequestResource.php` (claims section, per request) |

Total: 16.
