# Guest ordering — Launch checklist

Tick each box. Do not print any QR code until "Technical" is all ticked.
Text in bold is copied exactly from the screen.

## Technical (before printing anything)
- [ ] `APP_URL` in the production `.env` is the real `https://` address.
      QR codes are built from it. A wrong value prints dead codes.
- [ ] PHP has GD with WebP support.
      Check in the server terminal: `php -r "var_dump(gd_info()['WebP Support'] ?? false);"` must print `true`.
- [ ] Upload limit is at least 8 MB.
      Check: `php -r "echo ini_get('upload_max_filesize'), ' ', ini_get('post_max_size');"`.
- [ ] Built screen files are present.
      `public/build` is committed to git, and `deploy.sh` also builds it when Node is installed.
- [ ] The scheduler is running every minute.
      In DirectAdmin → Cron Jobs, this line must exist:
      `* * * * * cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan schedule:run >> /dev/null 2>&1`
- [ ] The guest jobs actually run. In the server terminal:
      1. `php artisan schedule:list`. It must list `guest:expire-stale` (every 10 minutes) and `guest:bar-monitor` (every minute).
      2. Wait two minutes. Run `php artisan schedule:list` again. "Next due" times must have moved on.
      3. Run `php artisan guest:expire-stale` once by hand. It prints how many orders and tables it closed.
- [ ] The stock sizing queries were run on production. The owner read the results.
      Phase 0B queries: `docs/audits/floorplan-add-item-verification.md`, section 5.
      Phase 0F queries: `docs/audits/phase-0F-verification.md`, section 2.
- [ ] The storekeeper and kitchen were told: ingredient counts now move at Mark Ready, not at order time.

## Content
- [ ] Transfer accounts entered in **Guest Ordering Settings** → **Transfer accounts**.
      Check each one: scan a table code, open **Bill** → **Pay by transfer**, tap **Copy**, paste it in a note.
- [ ] **Reception WhatsApp number** is correct. Default is 2348144734612.
- [ ] WhatsApp Business is installed on the venue phone at the reception desk.
- [ ] Porters have the Porter role. Their names appear under **Porters** in the settings.
- [ ] The **Missing photo** filter on Menu Items shows 0 (or a short list the owner accepts).
- [ ] Chips (for example "Cold", "Extra pepper") reviewed by the bar and kitchen.
- [ ] **Specials banner** set, with **Show the banner** on or off as wanted.
- [ ] QR cards printed from **QR Codes**: **Print all table cards (A6, 4 per page)** and **Print all room stickers (80 mm, 6 per page)**.
- [ ] Every printed code scanned once before it goes out.

## Soft launch plan
- Week 1: 3–4 tables only. Owner or manager on the floor.
- Week 2: all tables.
- Week 3: rooms.
- Move to the next week only if there is no open ❌ from the dry run.

## Daily (first two weeks)

Morning:
- [ ] Tap **Tap to enable order sounds** on every kiosk, bar screen and Room Orders page.
- [ ] Kitchen taps **Reset all to available** on the kitchen screen.

Night:
- [ ] Late bar shifts: there is no screen yet. Ask the developer for the wait-log report (see `discrepancies.md`).
- [ ] **Delivery Refusals**: nothing left on **Waiting for a manager**.
- [ ] Unmatched claims: ask waiters and reception which claims were not paid.
