# Phase 1A (Menu Content): Step 0 Check and Pre-Decisions

**Date:** 2026-10-01
**Status:** Step 0 done. **The build is on hold by the owner's decision until Phases 0A, 0B and 0C are complete.** Nothing was built for 1A.

## Step 0 findings

| Check | Finding |
|---|---|
| Image packages | **None installed.** `composer.json` has no `intervention/image` or `spatie/laravel-medialibrary`. Per the prompt, 1A will install `intervention/image` v3. |
| PHP image support (local, PHP 8.4.12) | `gd` with WebP and JPEG support, `exif`, `fileinfo`. **No `imagick`.** GD cannot decode HEIC. Production extensions are unverified. Check with `php -m` on the server before 1A. |
| Menu item model | `App\Models\MenuItem` (`menu_items`): `name, sku, category_id, type, sale_price, available_for_sale`. No photo or description columns. |
| `available_for_sale` | `tinyint(1)` on `menu_items`. Its only writer today is the admin form `Toggle::make('available_for_sale')` in `app/Filament/Resources/MenuItems/MenuItemResource.php`. It is in `MenuItem::$fillable`. |
| Category structure | Flat: `categories(name, type, commission_rate)`, where `type` is `food` / `drink` / `service`. There is no parent/child. Both `products.category_id` and `menu_items.category_id` point to it. |
| Drinks on the guest menu | Bar drinks are **`Product`** rows (`products`), not menu items. Products have no photo, description or `available_for_sale`. Their availability is bar stock. |
| Food that is a Product | Food-category Products also exist (e.g. packaged kitchen items). They have no `available_for_sale`; only `is_active` (which hides them from the POS entirely) or stock. The production split between food MenuItems and food Products is **unverified**, because the local DB is empty. |
| KDS authentication | `/kds` sits behind the `kiosk.device` middleware. A cook signs in through the board's own PIN pad (`kds-board.blade.php` `submitPin()` → `PinAuthService::attempt()` → `Auth::guard('staff_pin')->login()`) and stays signed in until "sign out". Every write re-checks `requireActiveCook()`. **There is no role or chef-shift check**: any staff member with a PIN can be the active cook. |
| Photo storage | `public/storage` is **not linked** locally (`php artisan about`), and `deploy.sh` never runs `storage:link`. Photos on the `public` disk would 404 unless the link exists on the server. 1A should either confirm the link on production or store photos in a gitignored folder under `public/` that needs no link. |
| Upload limits | Local `upload_max_filesize` / `post_max_size` are 2G. The production values are **unverified**; shared hosting often defaults to 2M, which would block the 8 MB rule. |

## Owner decisions (2026-10-01), to apply when 1A runs

1. **Run order:** finish Phases 0A, 0B and 0C before building 1A.
2. **Photos and descriptions go on both `menu_items` and `products`.** They use the same `MenuPhotoProcessor` and the same form fields on both Filament resources, so the guest Drinks tab can show photos.
3. **HEIC is always rejected.** Accept jpg, png and webp only. A HEIC upload gets a clear message, for example: "iPhone photo format isn't supported. Choose the photo again from Photos (it uploads as JPEG), or set Camera › Formats › Most Compatible."
