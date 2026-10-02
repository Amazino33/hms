# Phase 7A — Step 1 verification

Read-only check before the guest UI refresh. Date: 2026-10-02.

**Result: no conflict. One company-name source; no endpoint changes needed.**

## 1. Guest views, scripts and styles

| File | What it is |
|---|---|
| `resources/views/guest/menu.blade.php` | The guest page for `/m/{token}` (tables and rooms) and `/menu`. It contains every sheet: item, cart, sent, split, pay. It also holds the Bill and Call views (shown as tabs today) and the moved/closed banners. Room variants are branches on `boot.kind === 'room'`: the WhatsApp cart buttons, Call reception, and no split. |
| `resources/views/guest/invalid-code.blade.php` + `layout.blade.php` | The stand-alone "no longer valid" page (404). It uses inline CSS, with no Vite bundle. |
| `resources/js/guest.js` | One Alpine component, `guestMenu`: the menu, cart, requests polling, bill polling, round, split, pay, claims and calls. |
| `resources/css/guest.css` | The whole theme. Today it uses gold (`--accent: #E0B35A`) and red for errors (`--danger`). |
| `resources/fonts/*` | Self-hosted woff2 files, bundled by Vite through `guest.css`. |

There is **no separate "My orders" sheet** any more. Phase 4 moved this phone's pending orders, with their Cancel buttons, into the Bill view. "Moved" and "Closed" are banners on the same page, not separate pages.

## 2. Company name

- **The one store:** `companies.name` (the `App\Models\Company` row, id 1), edited on **Company Settings** (`ManageCompanySettings`).
- **Read for guests:** `Company::first()?->name`, with `config('app.name')` as a fallback **only when no company row exists**.
  - `GuestMenuController::brand()`, line 440.
  - `QrPrintSheets`, line 54.
- The Filament panels set no `brandName`.
- **Not two competing sources**, so no STOP. Phase 7A adds one accessor, `Company::displayName()`. The guest page and splash use it.

## 3. Logo pipeline

- **Original:** uploaded on Company Settings (`logo_path`). It sits on the **private** default disk (`storage/app/private/company-logos/…`).
- **Public copy:** written by `App\Services\BrandingLogo::publish()`, which runs when the Company is saved (model hook) and from `branding:publish-logo` (also in `deploy.sh`).
  - It produces `public/media/branding/logo.webp`, at most 400 px wide.
  - It is encoded by `MenuPhotoProcessor::webpFromPath()` (the 1A encoder).
- **Phase 7A adds:**
  - `logo-splash.webp` (480 px), from the same original, in the same calls;
  - `logo-mark.webp` (96 px), from a new optional "Small logo mark" upload on Guest Ordering Settings.
- `public/media/branding/*` is git-ignored.

## 4. Fonts

- **Today:** Fraunces 600 (latin woff2, 18 KB), copied from `@fontsource/fraunces` into `resources/fonts/`. It is loaded by an `@font-face` in `guest.css`, with `font-display: swap`. Body text uses the system font stack.
- **Phase 7A:**
  - **Cormorant Garamond 600** (`@fontsource/cormorant-garamond`, 23.4 KB).
  - **Manrope 400–800** as the single variable woff2 (`@fontsource-variable/manrope`, 24.8 KB, covering every weight the design asks for).
  - Total **48.2 KB** (budget 80 KB). Four static Manrope files would have been 56 KB on their own, 79.5 KB in total.
  - Fraunces is removed. No Google Fonts requests.

## 5. Page payload

The embedded `#guest-boot` JSON already has everything the new layout needs:

| Need | Where it comes from |
|---|---|
| Menu sections, items, photos (thumb + large), chips, descriptions | `boot.menu` |
| Availability | `boot.unavailable` (+ the `/availability` poll) |
| Popular tonight | `boot.popular` |
| Specials banner | `boot.specials` |
| Place pill, table/room, ordering mode, notices | `boot.place`, `boot.kind`, `boot.mode`, `boot.notice`, `boot.table` |
| Transfer accounts | `boot.accounts` |
| WhatsApp availability for rooms | `boot.whatsapp` |
| Bill, claims, calls, round | the existing JSON endpoints (unchanged) |
| Greeting (morning/afternoon/evening) | computed in the browser with Lagos time (`Africa/Lagos`); no data needed |
| Item count per section | computed from `boot.menu` |

**Missing (display-only) and added to the page:**
- the company name, through the new accessor;
- the logo URLs: header mark (falling back to the full logo) and splash.

These are passed as Blade view variables. They are not added to any JSON endpoint. **No endpoint changes.**
