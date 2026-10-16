# Phase 1 Audit — Staff Self-Registration, Approval, ID Card & Deactivation

**Date:** 2026-10-16
**Scope:** Read-only. No application file, migration, config, seeder or test was changed. No migration or seeder was run. Every database query was a `SELECT` against `information_schema` or a `COUNT`/`SUM`.
**Working tree:** `main` at `2f5c397` plus uncommitted work (the Order Integrity package). Where that uncommitted work touches this audit, it is called out.

> **About the data in Section 13:** the only database reachable from this machine is the **local dev database** (`hms` on 127.0.0.1). It has 8 seeded users, so its counts say nothing about production. Appendix A has the same queries, counts only, ready to run on the production server.

---

## 1. Staff identity model

### 1.1 Where staff identity lives
- **On `User` only** (`app/Models/User.php`, table `users`). There is **no separate Staff or Employee model**. Not found.
- The nearest thing to a second identity is **`App\Models\Attendance\AttendanceDeviceUser`** (table `attendance_device_users`). This is one badge on the ZKTeco terminal, not a person. It links to `users` through the effective-dated table `attendance_device_links` (`attendance_device_user_id` → `user_id`). `users.biometric_id` holds a denormalised copy of the current link (Section 5.3).

### 1.2 Columns on `users` (live local schema via `php artisan model:show User`, cross-checked against migrations)

| Column | Type | Null | Default | Index | Added by |
|---|---|---|---|---|---|
| id | bigint unsigned | no | auto | PK | `0001_01_01_000000_create_users_table.php:15` |
| biometric_id | varchar(255) | yes | — | **unique** `users_biometric_id_unique` | `2026_09_11_133114…:15`, unique in `2026_10_07_100300…:25` |
| shift_start_time | time | yes | — | — | `2026_09_11_133114…:16` |
| staff_code | varchar(255) | yes | — | **unique** | `2026_02_24_232805…:16` |
| job_title | varchar(255) | yes | — | — | `2026_10_07_090000…:25` |
| attendance_exempt | tinyint(1) | no | 0 | — | `2026_10_07_090000…:30` |
| name | varchar(255) | no | — | — | create_users |
| email | varchar(255) | no | — | **unique** | create_users |
| email_verified_at | timestamp | yes | — | — | create_users |
| password | varchar(255) | **yes** (made nullable) | — | — | create_users; nullable in `2026_10_07_090000…:21` |
| pin_hash | varchar(255) | yes | — | — | `2026_07_06_001442…:19` |
| pin_lookup_hash | varchar(255) | yes | — | **unique** | `2026_07_06_001442…:20` |
| pin_set_at | timestamp | yes | — | — | `2026_07_06_001442…:21` |
| two_factor_secret | text | yes | — | — | `2025_09_02_075243…:15` |
| two_factor_recovery_codes | text | yes | — | — | `2025_09_02_075243…:16` |
| two_factor_confirmed_at | timestamp | yes | — | — | `2025_09_02_075243…:17` |
| remember_token | varchar(100) | yes | — | — | create_users |
| created_at / updated_at | timestamp | yes | — | — | create_users |
| warehouse_id | bigint unsigned | yes | — | FK → warehouses, `ON DELETE SET NULL` | `2026_01_28_120000…:13` |
| primary_location | varchar(255) | yes | — | — | `2026_02_24_232805…:17` |
| id_type | varchar(255) | yes | — | — | `…232805…:20` |
| id_number | varchar(255) | yes | — | — | `…232805…:21` |
| id_card_copy | varchar(255) (file path) | yes | — | — | `…232805…:22` |
| guarantor_form | varchar(255) (file path) | yes | — | — | `…232805…:23` |
| base_salary | decimal(12,2) | no | 0 | — | `…232805…:26` |
| bank_name | varchar(255) | yes | — | — | `…232805…:27` |
| account_number | varchar(255) | yes | — | — | `…232805…:28` |
| account_name | varchar(255) | yes | — | — | `…232805…:29` |
| next_of_kin_name | varchar(255) | yes | — | — | `…232805…:32` |
| next_of_kin_phone | varchar(255) | yes | — | — | `…232805…:33` |
| left_at | timestamp | yes | — | — | `2026_07_22_100000_add_left_at_to_users_table.php:19` |

### 1.3 Model configuration (`app/Models/User.php`)
- **Traits** (line 24): `HasFactory`, `HasRoles` (Spatie), `LogsActivity` (Spatie v5), `Notifiable`, `TwoFactorAuthenticatable` (Fortify). **No `SoftDeletes`.** Implements `FilamentUser`. It does **not** implement `MustVerifyEmail` (the import is commented out at line 5).
- **`$fillable`** (lines 45–63): `name, email, password, staff_code, primary_location, id_type, id_number, id_card_copy, guarantor_form, base_salary, bank_name, account_number, account_name, next_of_kin_name, next_of_kin_phone, biometric_id, shift_start_time, job_title`.
  - These are deliberately **not** fillable: `pin_hash`, `pin_lookup_hash`, `pin_set_at` (written only via `forceFill` in `PinAuthService`), `attendance_exempt` (written only via `AttendanceExemptionService`), `left_at`, and `warehouse_id`.
- **`$guarded`:** not set (Eloquent's default applies).
- **`$hidden`** (lines 94–101): `password, two_factor_secret, two_factor_recovery_codes, remember_token, pin_hash, pin_lookup_hash`.
- **`casts()`** (lines 108–118): `email_verified_at => datetime`, `password => hashed`, `attendance_exempt => boolean`. **`left_at` has no cast.** It is read as a raw string. `app/Services/Attendance/ShiftScheduleResolver.php:248` documents this.
- **`$attributes`** (lines 85–87): `attendance_exempt => false`.
- **Activity log** (lines 30–38): `logFillable()->logExcept(['password'])->logOnlyDirty()`, log name `user`. So **every change to bank, ID-number, next-of-kin and staff-code fields is copied into `activity_log.properties`** (see Section 15).
- **Constant:** `ATTENDANCE_ONLY_ROLE = 'attendance_only'` (line 76).
- **Observers / hooks:**
  - `App\Observers\UserObserver`, registered at `app/Providers/AppServiceProvider.php:252`. On `updated`, `deleted` and `created` it clears the sidebar cache. On `created` and `saved` (only when `biometric_id` changed), it back-links past punches via `AttendanceLinker`.
  - Spatie `HasRoles::bootHasRoles` and `HasPermissions::bootHasPermissions` add `deleting` closures that detach roles and permissions (`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:23`, `HasPermissions.php:32`).

### 1.4 Relationships defined on `User`
| Method | Type | Target | Line |
|---|---|---|---|
| shifts | HasMany | Shift | 136 |
| currentShift() | helper (`shifts()->active()->first()`) | — | 144 |
| warehouse | BelongsTo | WareHouse | 339 |
| commissions | HasMany | Commission | 347 |
| debts | HasMany | StaffDebt | 355 |
| salaries | HasMany | StaffSalary | 363 |
| payrollLines | HasMany | PayrollLine | 371 |
| attendanceShiftAssignments | HasMany | Attendance\AttendanceShiftAssignment | 379 |
| attendanceShiftRecords | HasMany | Attendance\AttendanceShiftRecord | 389 |
| attendanceFines | HasMany | Attendance\AttendanceFine | 394 |
| roles, permissions | MorphToMany | Spatie | trait |
| activitiesAsSubject | MorphMany | Activity | trait |
| notifications | MorphMany | DatabaseNotification | trait |

There is no relation on `User` for: trusted devices, cashier sessions, cash drops, count sessions, order payments, attendance logs, device links, or staff-debt repayments. Those exist only as foreign keys on the child side (Section 8).

---

## 2. How staff are created today

### 2.1 Filament surfaces that create or edit staff

**`App\Filament\Resources\Users\UserResource`**
- Panel: **admin only** (`/admin/users`, `/admin/users/create`, `/admin/users/{record}/edit`).
- Gated by the Shield policy `app/Policies/UserPolicy.php` (`ViewAny:User`, `Create:User`, `Update:User`, `Delete:User`, …).
- Seeded holders of the User permissions (`database/seeders/ShieldSeeder.php` JSON): **`super_admin`** (all of them) and **`admin`** (`ViewAny/View/Create/Update/Delete:User`). No other seeded role holds them. Production may differ, because roles are also edited live through the Shield UI.

**Form fields and validation** (`UserResource::form`, lines 91–290):

| Field | Rules / behaviour | Line |
|---|---|---|
| name | `required`, `maxLength(255)` | 95 |
| email | `email`, `required`, `maxLength(255)`, `unique(ignoreRecord)` — **required even for attendance-only staff** | 99 |
| staff_type (form-only) | ToggleButtons `app` / `attendance_only`, `dehydrated(false)` | 109 |
| password | `password()`; hashed; saved only if filled; required on create unless attendance-only; hidden for attendance-only | 133 |
| staff_code | `unique(ignoreRecord)`, free text, placeholder "e.g. LH-001", **editable, no format rule** | 148 |
| roles | `Select->relationship('roles','name')`, multiple, excludes `attendance_only` from options; required for app users | 157 |
| primary_location | Select `main_bar` / `restaurant` / `kitchen` | 167 |
| job_title | free text | 173 |
| biometric_id | read-only (`disabled`, `dehydrated(false)`); written only by `DeviceLinkService` | 182 |
| shift_start_time | TimePicker (legacy late-fine time) | 189 |
| base_salary | numeric, default 0 | 209 |
| total_commission | read-only display | 214 |
| bank_name | Select of 21 hard-coded Nigerian banks (`getNigerianBanks()`, line 363); stores a slug (e.g. `gtbank`), not the bank's name | 228 |
| account_number | `numeric`, `minLength(10)`, `maxLength(10)`, `requiredWith:bank_name` | 234 |
| account_name | `minLength(3)`, `maxLength(255)`, `requiredWith:bank_name` | 240 |
| id_type | Select `nin` / `voters` | 253 |
| id_number | free text, no rules | 255 |
| id_card_copy | FileUpload, directory `staff-docs`, default disk, **no type or size rules** | 256 |
| guarantor_form | FileUpload, directory `guarantors`, default disk, **no type or size rules** | 258 |
| next_of_kin_name | free text | 267 |
| next_of_kin_phone | `tel()` | 268 |

**Page logic:**
- `Pages/CreateUser.php`: strips `staff_type`. For attendance-only, it nulls the password and calls `AttendanceOnlyRoleService::makeAttendanceOnly()` after create.
- `Pages/EditUser.php`: header actions are "Exempt from attendance" (super admin only, via `AttendanceExemptionService::canChange`) and **`DeleteAction::make()`** (line 455, a hard delete). `afterSave` converts between app user and attendance-only.
- Table actions (`UserResource.php:312–343`): Edit, **Force Reset PIN** (`PinAuthService::forceReset`), and a "Delete" action that only links to the edit page.
- Relation managers: `ScheduleRelationManager`, `AttendanceRelationManager`.

**`App\Filament\Resources\DeviceUsers\DeviceUserResource`** (admin panel, `/admin/device-users`)
- Policy `app/Policies/Attendance/AttendanceDeviceUserPolicy.php`. Seeded holders: `super_admin`, `admin`.
- The **"Create staff & link"** action (lines 120, 158) calls `DeviceLinkService::createStaffAndLink()` (`app/Services/Attendance/DeviceLinkService.php:226–247`). It creates a `User` with a placeholder email `device-{id}-{random}@attendance.local` (line 307) and makes them attendance-only.

**CEO panel:** creates nothing.

### 2.2 Seeders, commands and other code that create users
- `database/seeders/DatabaseSeeder.php:32`: `User::firstOrCreate(test@example.com)` with `super_admin`, **non-production only**.
- `app/Actions/Fortify/CreateNewUser.php:27`: Fortify public registration (2.3).
- `DeviceLinkService::createStaffAndLink` (above).
- No custom artisan command in `app/Console` creates users. Vendor commands that can, if run by hand: `shield:super-admin`, `make:filament-user`.

### 2.3 Public or unauthenticated routes that create or modify users — **YES, one exists**
- **`GET /register` and `POST /register`** (Fortify `RegisteredUserController`). These are enabled by `Features::registration()` in `config/fortify.php:147`, with the view at `resources/views/pages/auth/register.blade.php`.
  - Creates a `User` with name, email and password and **no role** (`CreateNewUser.php:20–31`), then logs them in on the `web` guard.
  - **No throttle.** `config/fortify.php:117` only defines the `login` and `two-factor` limiters.
  - `tests/Feature/Auth/RegistrationTest.php` asserts that this works and authenticates the new user.
  - The resulting user is denied every Filament panel (`User::canAccessPanel`, zero-role branch). But see Section 15, item R1.
- **`POST /forgot-password` and `POST /reset-password`** (Fortify) modify an existing user's password. This is standard.
- **`/settings/profile`** (auth only, `routes/settings.php:9`) lets any web-authenticated user change their own name and email, and **delete their own account** (`resources/views/pages/settings/delete-user-form.blade.php:16–22`). The form shows for every user, because `User` does not implement `MustVerifyEmail` (`profile.blade.php:72–76`).
- `/kiosk/register` registers a **device**, not a user.
- `/iclock/*` (ZKTeco) writes `attendance_logs` and `attendance_device_users`, never `users`.

---

## 3. Roles & permissions

### 3.1 Roles
Seeded in `database/seeders/ShieldSeeder.php` (JSON in `rolesWithPermissionsJson()`), with permission counts:
`super_admin` (309), `admin` (87), `manager` (58), `chef` (3), `bartender` (3), `storekeeper` (4), `supervisor` (1: `GrantGuestCredit`), `waiter` (0), `receptionist` (0), `porter` (0), `cashier` (0), `ceo` (0), `attendance_only` (0).
- Shield's config also enables a `panel_user` role (`config/filament-shield.php:87–90`).
- The local dev DB has 11 roles. `supervisor` and `attendance_only` are missing locally.
- `PayrollCompilationService.php:47` notes that `supervisor` was originally added directly in production.

### 3.2 How a user's role is assigned
- **The UserResource `roles` Select** writes the Spatie pivot directly through Filament's relationship sync (`UserResource.php:157–166`).
  - It does not go through any service.
  - The only restriction is that `attendance_only` is hidden from the options.
  - **Any user holding `Update:User` or `Create:User` (seeded: `admin`) can assign `super_admin` or `ceo`.**
- `AttendanceOnlyRoleService::makeAttendanceOnly` / `makeAppUser` (`app/Services/Attendance/AttendanceOnlyRoleService.php:46, 79`) call `syncRoles` and log to the activity log.
- The Shield Role resource (`/admin/shield/roles`) edits role→permission mappings, not user→role.
- On deploy, `deploy.sh:95–110` grants permissions additively (`givePermissionTo`) from the `ShieldSeeder` JSON. It never assigns roles to users.

### 3.3 Permission naming and custom permissions
- **Convention:** Pascal case with a `:` separator, `Action:Model`, for example `ViewAny:User` and `Update:StaffDebt` (`config/filament-shield.php:105–109`). Page and widget permissions use the `view` prefix with the class as subject (lines 192–213).
- **Custom permissions:** defined in `config/filament-shield.php` → `custom_permissions` (currently only `'GrantGuestCredit' => 'Grant guest credit'`). The Custom Permissions tab is turned on (`shield_resource.tabs.custom_permissions => true`, line 28).
  - The pattern end to end:
    1. A constant on the service: `FastMarkPaidService::GRANT_GUEST_CREDIT = 'GrantGuestCredit'`, at `app/Services/FastMarkPaidService.php:42`.
    2. A check with `$approver->can(self::GRANT_GUEST_CREDIT)` (line 212). The approver is resolved by PIN (line 199).
    3. The permission name is added to the relevant roles in the `ShieldSeeder` JSON (`super_admin`, `manager`, `supervisor`) so `deploy.sh` grants it additively.
  - **Note:** `config/filament-shield.php` is **untracked** (`??` in `git status`), and the `GrantGuestCredit` change to `ShieldSeeder.php` is uncommitted. The custom-permission pattern exists only in the working tree until that work is committed.
- **Pages** use the separate `PagePermission` mechanism: `PermissionService::canAccessPage()` at `app/Services/PermissionService.php:184–208`, which lets super admin through and denies by default.
  - Unlike what CLAUDE.md says, there **is** seed data. `database/seeders/PagePermissionsSeeder.php` has about 127 entries and `deploy.sh:112` runs it on every deploy (`firstOrCreate`, additive).

### 3.4 Position or department separate from role
- **No department model or table.** Not found.
  - `PermissionService::canManageIngredients()` (line 32) reads `$user->departments`. That relation does not exist, so it always evaluates to null.
- Position-like fields that do exist, all free-standing columns:
  - `users.job_title`: free text. Placeholder "Cleaner, Laundry, Waiter".
  - `users.primary_location`: enum-like `main_bar` / `restaurant` / `kitchen`.

### 3.5 How the super admin is identified
- **The role name `super_admin`**, checked with `hasRole('super_admin')`:
  - `User::canAccessPanel` (line 401)
  - `PermissionService::canAccessPage` (line 193)
  - `AttendanceExemptionService::canChange`
  - and others.
- Shield's `super_admin` is enabled with `define_via_gate => false` (`config/filament-shield.php:69–74`). So **there is no `Gate::before` bypass**: `super_admin` passes Shield policies only because it explicitly holds all 309 permissions. A grep for `Gate::before` / `Gate::define` in `app/` finds nothing.
- There is no `is_super_admin` flag column.

---

## 4. Authentication surfaces

### 4.1 Kiosk PIN storage (`app/Services/PinAuthService.php`)
- `users.pin_hash`: bcrypt of the PIN (`Hash::make`, line 65). Used for verification.
- `users.pin_lookup_hash`: `hash_hmac('sha256', $pin, APP_KEY)` (line 155). Deterministic, with a **unique index** (`2026_07_06_001442…:20`). It is used to find "who typed this".
- `users.pin_set_at`: timestamp.
- **Uniqueness:** a PIN is unique across **all users** (`setPin`, lines 53–59). When a PIN is already taken, the error says *"That PIN is already in use by someone else"* (line 58).
- Rules: exactly 4 digits; rejects all-same digits and ascending/descending runs (`trivialPins()`, lines 19–34).
- **Self-service only**, through `/settings/pin` (`resources/views/pages/settings/pin.blade.php`), which needs a `web` session. So a person needs a password and a web login at least once to set a PIN.
  - A manager can only clear a PIN (`forceReset`, line 75, logged to the activity log).
- Lockout: tiered per throttle key (5 failures → 60 s × tier), stored in the cache (lines 128–145).
- `attempt()` (line 97) checks only the PIN. **It does not check roles, `left_at` or `attendance_only`.**

### 4.2 PIN versus email/password
The code makes **no role-based split**. Any user with a password can attempt email/password login. Any user with a PIN set can use PIN surfaces.

| Surface | Credential | Guard | Gate |
|---|---|---|---|
| `/admin` (Filament) | email + password | `web` | `User::canAccessPanel`: any role except `ceo`-only and `attendance_only` |
| `/ceo` (Filament) | email + password | `web` | `canAccessPanel`: `super_admin` or `ceo` |
| `/login`, `/dashboard`, `/settings/*` (Fortify/Volt) | email + password | `web` | `auth` only, **no role check** |
| `/staff/login` (`StaffLoginController`) | email + password, validated **without** starting a session | none; trusts the device | `throttle:login`; **no role or `left_at` check** (lines 69–75) |
| `/kiosk`, `/staff` idle screen | 4-digit PIN | `staff_pin` | `kiosk-idle-screen.blade.php:119–158`: PIN match; on a personal device the PIN must belong to the device owner |
| `/kds` | PIN per action | `staff_pin` | `kds-board.blade.php:79–91` |
| Handover / dual-PIN seal | PIN | none (identity only) | `CountSessionService.php:506, 548, 642`; `ShiftManager.php:301` |
| Guest credit approval | PIN + `GrantGuestCredit` | none | `FastMarkPaidService.php:199–214` |
| Guest orders strip | PIN | none | `guest-orders-strip.blade.php:59` |

`attendance_only` users have no password and no PIN; `AttendanceOnlyRoleService::makeAttendanceOnly` (lines 53–58) clears both.

### 4.3 Devices and how they relate to users
- **Kiosk devices** (`kiosk_devices`): shared and not bound to a staff member.
  - `registered_by` → users is `ON DELETE CASCADE`. `revoked_by` → users is `SET NULL`.
  - A device is registered through a one-time `kiosk_registration_codes` row (`created_by` → users, CASCADE), via `KioskDeviceService`.
- **Trusted devices** (`trusted_devices`): **bound to one user** (`user_id` → users, CASCADE).
  - Created by `TrustedDeviceService::trust()` after `/staff/login`.
  - `resolveToken()` checks only `revoked_at` (lines 32–46), not the user's status.
- Deactivating a user (setting `left_at`) **does not revoke their trusted devices.** Nothing in code links the two.

---

## 5. Employment & attendance data already on staff

### 5.1 Shift times, work days, rotation
- **Current system:** `attendance_shift_templates` holds:
  - `start_time`, `duration_minutes`
  - `pattern_type` enum `weekly` / `rotation`
  - `weekly_days` (json)
  - `rotation_on_days` / `rotation_off_days`
  - `is_handover`, `retired_at`

  These are assigned to people through **`attendance_shift_assignments`**:
  - `user_id` (CASCADE)
  - `attendance_shift_template_id`
  - `effective_from` / `effective_to`
  - `weekly_days_override` (json)
  - `rotation_anchor_date`, `reason`, `created_by`, `ended_by`

  Managed through `ScheduleRelationManager` on the user edit page and `ShiftTemplates` resource; resolved by `ShiftScheduleResolver`.
- **Legacy:** `users.shift_start_time`. It is labelled in the form as "Legacy late-fine start time … still live until then" (`UserResource.php:189–193`). It is still read by `ZKTecoController` to apply the old ₦500 penalty (`app/Http/Controllers/ZKTecoController.php` around line 255: `if ($user && $user->shift_start_time) checkAndApplyPenalty`).
- **Operational POS shifts** (`shifts` table) are unscheduled and separate from attendance.

### 5.2 Salary history
- **`staff_salaries`** (`2026_07_22_100100_create_staff_salaries_table.php`):
  - `user_id` (FK, CASCADE), `amount` decimal(12,2), `effective_from` date
  - `created_by` (SET NULL), `notes`
  - `supersedes_id` (self-FK, SET NULL)
- Model `App\Models\StaffSalary`: append-only by convention (docblock), `LogsActivity`. Lookup with `StaffSalary::effectiveFor($user, $date)`.
- **Read by:** `PayrollCompilationService.php:120`. Payroll's base pay comes **only** from this table.
- **Written by:** **only a one-time copy inside its own migration** (`2026_07_22_100100_create_staff_salaries_table.php:37–56`). When payroll launched (22 Jul 2026), every user's `users.base_salary` was copied in as one row with `created_by = NULL`. **No ongoing writer exists**: a grep for `StaffSalary` across `app/`, `database/seeders/` and `resources/views/` finds no create path, form or seeder. The model docblock and the migration comment both say "a raise is a new dated row", but nothing in the app creates one.
- **`users.base_salary` is still edited on the User form, but since July nothing reads it.** Its only reference is `$fillable`. So:
  - A salary changed on the form after July never reaches payroll.
  - Anyone added after July has no salary row at all, so payroll base pay is ₦0.
  - See R6 and the production check in Section 13.

### 5.3 ZKTeco device user mapping
- **`users.biometric_id`**: unique, "Device user ID from ZKTeco".
  - Read-only on the form. Written only by `DeviceLinkService` (docblock line 15; writes at lines 57, 104, 174).
- **`attendance_device_users`**:
  - `device_user_id` (unique), `device_name`
  - `first_seen_at` / `last_seen_at`
  - `retired_at` / `retired_by` / `retired_reason`

  Created by the terminal push (`DeviceUserReconciler`) and the importer (`DeviceUserImporter`). There is no manual create (`DeviceUserResource::form` returns an empty schema, line 66).
- **`attendance_device_links`**: `attendance_device_user_id` → `user_id`, `effective_from` / `effective_to`, `voided_at` / `voided_by` / `void_reason`, `created_by`. Effective-dated history of who held a badge.
- **Device users with no Selum record (cleaner, laundry):**
  - They show as **"unmatched"**: `AttendanceDeviceUser::scopeUnmatched`, defined at `app/Models/Attendance/AttendanceDeviceUser.php:76–80` as not retired and with no active link. They also show in a nav badge count (`DeviceUserResource.php:52–62`).
  - Their punches are stored in `attendance_logs` with `user_id = NULL` (`ZKTecoController.php` around line 240: `User::where('biometric_id', …)->first()`, then `'user_id' => $user?->id`).
  - The admin resolves them through **"Create staff & link"**, which creates an `attendance_only` User with a placeholder email, or by linking to an existing user. Linking later re-attributes past punches (`AttendanceLinker`, `DeviceLinkService::reattributePunches`).

### 5.4 Date joined / employment start date
- **Not found.** There is no `hired_at`, `joined_at`, `employment_start` or `start_date` column on `users`.
- `users.created_at` is the account creation time, not the employment date.
- The nearest proxies are `staff_salaries.effective_from`, `attendance_shift_assignments.effective_from` and `attendance_device_links.effective_from`. None of them is a dedicated field.

---

## 6. Personal data fields — inventory

| Field | Status |
|---|---|
| phone (staff's own) | **NOT FOUND** (only `next_of_kin_phone`) |
| email | EXISTS — `users.email`. Required and unique. Placeholder `@attendance.local` emails are used for attendance-only staff. |
| home address | **NOT FOUND** |
| photo / avatar | **NOT FOUND** |
| date of birth | **NOT FOUND** |
| gender | **NOT FOUND** |
| next of kin — name | EXISTS — `users.next_of_kin_name` |
| next of kin — phone | EXISTS — `users.next_of_kin_phone` |
| next of kin — relationship | **NOT FOUND** |
| bank name | EXISTS — `users.bank_name`. Stores a slug from a fixed list of 21 banks. |
| account number | EXISTS — `users.account_number`. varchar; the form enforces 10 digits. |
| account name | EXISTS — `users.account_name` |
| guarantor — any structured field (name, phone, address, relationship) | **NOT FOUND** |
| guarantor — document | EXISTS — `users.guarantor_form` (file path, directory `guarantors`) |
| means of ID — type | EXISTS — `users.id_type` (`nin` / `voters` only) |
| means of ID — number | EXISTS — `users.id_number` |
| means of ID — upload | EXISTS — `users.id_card_copy` (file path, directory `staff-docs`) |
| state of origin | **NOT FOUND** |
| staff ID / employee number | PARTIAL — `users.staff_code`. Unique and nullable, but free text, editable, no format, and no sequence. |

---

## 7. Deactivation / exit

### 7.1 What exists
- **`users.left_at`** (nullable timestamp, `2026_07_22_100000…`). Null means current staff; a timestamp means they left.
  - Not fillable and not cast.
  - **There is no UI or service in `app/` that writes it.** Every reference is a read (`whereNull('left_at')` or `->left_at`). Only tests set it, via `forceFill` or the factory.
- No `is_active`, no `status`, no `deactivated_at`, and **no `SoftDeletes`.**
- Related flags that are not deactivation:
  - `attendance_exempt` (skip attendance judgement).
  - `attendance_only` role (no app access).
  - `attendance_device_users.retired_at` (retires a badge, not a person).

### 7.2 Does `left_at` block things?

| Check | Status | Where |
|---|---|---|
| (a) Panel login | **MISSING** | `User::canAccessPanel` (lines 399–440) never reads `left_at`. Fortify `/login` has no check either. |
| (b) Kiosk PIN login | **MISSING** | `PinAuthService::attempt` (line 97), `kiosk-idle-screen.blade.php:136–158`, `kds-board.blade.php:79–91`. `StaffLoginController::login` and `TrustedDeviceService::resolveToken` don't check it either. |
| (c) Shift start | **MISSING** | `User::startShift` (line 182), `ReceptionistShiftService::startShift` (line 23), `BartenderChefShiftService::startOpeningShift` (line 29). None reads `left_at`. |
| (d) Pickers and tiles | **PARTIAL** — see the list after this table | |
| (e) Payroll inclusion | **PRESENT** | `PayrollCompilationService::eligibleStaff` (lines 52–65): `whereNull('left_at')`. Anyone with `left_at` set is excluded from a run, **including a run for the period they left in**. |

Pickers and tiles for (d):

- **Respect `left_at`:**
  - Porters in `GuestOrdering.php:128` and `RoomOrders.php:264`
  - Porter assignment `RoomDeliveryService.php:62`
  - Device-user link pickers `DeviceUserResource.php:139, 227`
  - `StaffWithoutScheduleWidget.php:39`
  - `ShiftAssignmentService.php:108`
  - `AssignAttendanceSchedule` command (line 266)
  - Announcements `AnnouncementService.php:134, 155`
  - `AttendanceDeviceUser.php:102` (name suggestions)
  - `ShiftScheduleResolver.php:44` (stops scheduling after the leaving date)
- **Do NOT respect `left_at`:**
  - Staff debt "user" picker `StaffDebtForm.php:19`
  - Waiter filter `WaiterLedger.php:193`
  - Custodian picker `HandoverDiscrepancies.php:130`
  - Pickers `CountSessions.php:137, 221, 227`
  - Handover counterpart list `MyCount.php:184` (`User::role($role)`)
  - Filters `ActivityLogPage.php:142` and `AttendanceBoard.php:210`
  - Board rows `AttendanceBoard.php:126` (`attendance_exempt` only)
  - `ShiftFinaliser.php:495` (deliberate, per the comment at line 492)
  - `ShortageReports.php:72`
  - Order user selects `OrderResource.php:93, 353`
  - CEO `SalesReport.php:119`, `WaiterLedger.php:44` and `CountBreakdowns.php:70`. These are historical, so arguably correct.
  - `WaiterLedgerService.php:159`
  - Notification fan-outs that would still notify a leaver: `OrderSplitter.php:246`, `BarDisplay.php:263`, `KitchenOrderService.php:82`, `StaffDebtObserver.php:37`, `CountSessionService.php:706`, `StockTransferService.php:648`, `OrderPaymentVerificationService.php:91`, `GuestBarMonitor.php:98`, `RoomDeliveryService.php:286`.
- Guest-table waiter handover only offers waiters with an active, non-stale waiter shift (`ShiftManager.php:271`, `GuestSessionHandoverService.php:29`). A leaver appears there only if they still have an open shift.

### 7.3 What happens when a user is deleted today
- **Who can delete:**
  - Admin panel `EditUser` → `DeleteAction` (`Delete:User` permission: `super_admin`, `admin`).
  - **Any web-authenticated user can delete their own account** at `/settings/profile` (password confirmation only).
  - No soft delete, so it is a hard `DELETE FROM users`.
- **Order of events:**
  1. Spatie's `deleting` hooks detach `model_has_roles` and `model_has_permissions` first.
  2. Then the row delete runs.
  3. The admin panel does **not** enable `databaseTransactions()` (`AdminPanelProvider.php`), and Filament's `DeleteAction` does not wrap the delete in a transaction by default (`vendor/filament/actions/src/Concerns/CanUseDatabaseTransactions.php:10`). So if the row delete fails, **the role detach has already been committed**.
- **Database behaviour** (Section 8) is mixed across 111 foreign keys to `users`:
  - **17 are `NO ACTION` (restrict).** If the user has any `order_payments`, `payroll_lines`, `staff_debts.created_by`, `staff_debt_repayments.recorded_by`, `stock_transfers`, `folio_lines.created_by` and so on, **the delete fails** with an integrity error. The user is then left with no roles (step 3 above).
  - **31 are `CASCADE`.** If the delete succeeds, it silently removes their `shifts`, `commissions`, `staff_debts`, `inventory_transactions`, `ingredient_transactions`, `cash_drops` (both as waiter and as receiver), `cashier_sessions`, `count_sessions` they opened (and through them `count_session_items`), `staff_salaries`, `salary_deductions`, attendance records and fines, and `trusted_devices`. Second-order cascades follow: `procurement_items` via `inventory_transactions`, `shift_channel_confirmations`, `cash_drops` and `owner_take_notes` via `shifts`, and `staff_debt_repayments` via `staff_debts`.
  - **63 are `SET NULL`.** Orders, bookings, guest sessions and similar keep the row but lose who did it.
  - **No FK at all** (orphaned): `activity_log.causer_id`, `sessions.user_id`, `notifications.notifiable_id`, and the `daily_attendances` view.

---

## 8. Everything that references a staff member

Source: the live local schema, `information_schema.REFERENTIAL_CONSTRAINTS` where the referenced table is `users`. That gives **111 foreign keys**, plus the columns with no FK listed at the end. The local DB has 3 pending migrations (listed at the end of this section); their extra foreign keys are noted there.

### CASCADE (deleting the user deletes these rows)
| Table | Column | Domain note |
|---|---|---|
| shifts | user_id | **shifts / settlements** (a second-order cascade also deletes cash_drops, owner_take_notes and shift_channel_confirmations) |
| commissions | user_id | **commissions** |
| staff_debts | user_id | **StaffDebt** (cascades to staff_debt_repayments; blocked if payroll_line_deductions reference it) |
| staff_salaries | user_id | **salary history** |
| salary_deductions | user_id | surcharges / fines on pay |
| attendance_fines | user_id | **fines** |
| attendance_shift_records | user_id | attendance judgements (cascades to attendance_fines) |
| attendance_shift_assignments | user_id | schedule |
| attendance_device_links | user_id | badge history |
| cash_drops | waiter_id, received_by | **cash drops** |
| cashier_sessions | user_id | **cashier custody** (cascades to cashier_session_outflows) |
| count_sessions | opened_by | **bartender/chef handover** (cascades to count_session_items) |
| handover_discrepancy_recounts | counted_by, ordered_by, witnessed_by | handover recounts |
| inventory_transactions | user_id | **stock ledger** (cascades to procurement_items) |
| ingredient_transactions | user_id | kitchen stock ledger (cascades to procurement_ingredient_items) |
| room_supply_transactions | user_id | room supply ledger |
| stock_adjustments | requested_by | four-eyes adjustments |
| unreturnable_voids | manager_id | voids |
| damage_reports | reported_by | |
| expenses | entered_by | |
| fridge_restock_marks | marked_by | |
| owner_take_notes | recorded_by | |
| product_deletion_requests | requested_by | |
| kiosk_devices | registered_by | |
| kiosk_registration_codes | created_by | |
| trusted_devices | user_id | |
| announcement_recipients | user_id | |
| announcement_acknowledgements | user_id | |

### NO ACTION / RESTRICT (the delete fails while these exist)
| Table | Column | Domain note |
|---|---|---|
| order_payments | user_id | **settlements / payments** |
| payroll_lines | user_id | **payroll lines** |
| payroll_runs | prepared_by | |
| staff_debts | created_by | |
| staff_debt_repayments | recorded_by | **StaffDebtRepayment** |
| stock_transfers | user_id | **transfer handshake** |
| folio_lines | created_by | |
| cashier_session_outflows | created_by | |
| count_session_item_reviews | reviewed_by | handover peer review |
| booking_room_supply_usages | recorded_by | |
| procurements | recorded_by | |
| report_exports | exported_by | |
| room_changes | changed_by | |
| table_moves | moved_by_user_id | |
| guest_contacts | recorded_by_user_id | |
| guest_delivery_refusals | recorded_by_user_id | |
| guest_session_handovers | to_user_id | guest table handover |

### SET NULL (the row survives; who did it is lost)
| Table | Column(s) | Domain note |
|---|---|---|
| orders | user_id, processed_by_user_id, picked_up_by, kds_picked_up_by | **orders** |
| order_payments | verified_by, flagged_by, ruled_by | |
| shifts | cash_confirmed_by, pos_confirmed_by, pos_ruled_by | **cashier confirmations** |
| shift_channel_confirmations | confirmed_by, ruled_by | **cashier confirmations** |
| cashier_sessions | closed_by | |
| count_sessions | outgoing_user_id, incoming_user_id, witness_user_id, reviewed_by, cancelled_by | **bartender / chef handover** |
| handover_discrepancies | resolved_by | |
| transfer_discrepancies | resolved_by | |
| stock_transfers | cancelled_by | |
| stock_transfer_items, ingredient_transfer_items | received_by | **transfer handshake** |
| stock_adjustments, product_deletion_requests | reviewed_by | |
| payroll_lines | paid_by, closed_by | |
| payroll_runs | voided_by | |
| staff_salaries | created_by | |
| guest_table_sessions | assigned_waiter_user_id, closed_by_user_id | **guest QR waiter assignment** |
| guest_request_items | porter_user_id, released_by_user_id | **porter custody** |
| guest_requests | confirmed_by_user_id | |
| guest_session_handovers | from_user_id | |
| guest_waiter_calls | acknowledged_by_user_id | |
| guest_delivery_refusals | decided_by_user_id, bar_return_confirmed_by_user_id | |
| attendance_logs | user_id | **attendance punches** |
| attendance_review_items | user_id, resolved_by | |
| attendance_fines | voided_by | |
| attendance_device_links | created_by, voided_by | |
| attendance_device_users | retired_by | |
| attendance_shift_assignments | created_by, ended_by | |
| attendance_shift_templates, attendance_settings | created_by | |
| bookings | created_by_user_id, checked_in_by, checked_out_by | |
| folio_lines | verified_by | |
| damage_reports | resolved_by | |
| expenses | voided_by | |
| kitchen_waste_logs | recorded_by | |
| menu_item_availability_logs | user_id | |
| ingredients, products | created_by | |
| kiosk_devices | revoked_by | |
| qr_token_regenerations | regenerated_by | |
| settings | updated_by | |
| announcements | created_by | |

### Columns with no FK constraint (orphaned after a delete)
| Table | Column | Note |
|---|---|---|
| activity_log | causer_id / causer_type | **activity log causer** (polymorphic) |
| model_has_roles / model_has_permissions | model_id | cleaned by Spatie's `deleting` hook |
| notifications | notifiable_id | |
| sessions | user_id | |
| daily_attendances (VIEW) | user_id | derived from attendance_logs |

### Requested items that were not found
- **"Bar recording tabs":** no table, model or column by that name. `bar_shift_wait_logs` and `bar_shift_conflict_logs` have no user columns.
- **Chef/kitchen handover:** shares `count_sessions` with the bartender handover. There is no separate table.

### Pending migrations (uncommitted Order Integrity work)
`2026_10_09_090000_add_integrity_stamps_to_orders_table.php:31–32` adds `orders.released_by_user_id` and `orders.credit_approved_by_user_id`, both `nullOnDelete`. Lines 28–29 add `orders.bartender_shift_id` and `chef_shift_id` → `shifts`, `nullOnDelete`. Because `shifts.user_id` cascades, deleting a bartender would null these on their orders.

The committed but locally pending `2026_10_08_100000_create_count_breakdown_tables.php:123` adds a `user_id` FK with `nullOnDelete`. Its `count_session_id` FKs cascade, so a user delete that cascades `count_sessions` would also delete the count breakdowns.

---

## 9. Company settings

### 9.1 Company name and logo
- **Model `App\Models\Company`** (table `companies`):
  - Columns: `name`, `address`, `phone_number`, `logo_path`, `handover_count_scope`, `enforce_kitchen_ingredient_stock`, `require_chef_shift_for_kitchen_orders`, `maintenance_*`.
  - Single row, `id = 1`.
- **Guest splash trace:**
  1. `GuestMenuController::brand()` (`app/Http/Controllers/GuestMenuController.php:504–511`) returns `'venue' => Company::displayName()`, plus `BrandingLogo::headerUrl()` and `splashUrl()`.
  2. `Company::displayName()` (`app/Models/Company.php:17–20`) returns `Company::first()?->name`, falling back to `config('app.name')`.
  3. Rendered at `resources/views/guest/menu.blade.php:47` (`<p class="name">{{ $venue }}</p>`) and in the `<title>` at line 27.
  4. The same source is used by `QrPrintSheets.php:54`.
- **Logo:**
  - The original upload (`logo_path`) is on Filament's default disk, which is private `local` on this machine.
  - `BrandingLogo::publish()` (`app/Services/BrandingLogo.php:77–95`) writes public WebP copies to the `branding` disk (`public/media/branding/`). It is triggered by the `Company::saved` hook (`Company.php:27–35`) and by `branding:publish-logo` (which `deploy.sh` runs).
  - PDFs embed the private original as a data URI (`QrPrintSheets::logoDataUri`, lines 64–80).
- The admin panel sets **no brand name or logo** (`AdminPanelProvider.php` has no `brandName` / `brandLogo`). It sets only `colors(['primary' => '#E5353A'])`.

### 9.2 Settings mechanism
There are two in use. `spatie/laravel-settings` is not installed.
1. **Columns on `companies`**, edited on **`ManageCompanySettings`** (`app/Filament/Pages/ManageCompanySettings.php`, `/admin/manage-company-settings`).
   - Gated by `PermissionService::canAccessPage` (line 366). Not in `PagePermissionsSeeder`, so it is **super admin only** unless granted in the live UI.
   - **Existing boolean toggles live here:** `Toggle::make('enforce_kitchen_ingredient_stock')` (line 142) and `Toggle::make('require_chef_shift_for_kitchen_orders')` (line 148). They are saved by `Company::updateOrCreate(['id'=>1], $companyData)` (line 285).
   - Pattern for adding a boolean toggle this way:
     1. A migration adds `$table->boolean('x')->default(…)` to `companies`. See `2026_08_01_140000_add_require_chef_shift_for_kitchen_orders_to_companies_table.php`.
     2. Add the key to `mount()` → `$this->form->fill([...])`.
     3. Add a `Toggle::make('x')` to the schema.
     4. `save()` routes every non-`SettingsService` key to the Company row automatically.
     5. Read it with `Company::first()->x`.
2. **Key/value `settings` table** via **`App\Services\SettingsService`** (`app/Services/SettingsService.php`). It is cached for 1 hour and every write is activity-logged with an explicit causer.
   - Booleans use `SettingsService::getBool($key, $default)` and `setBool($key, $bool, $actorId)`. A live example is `GuestOrderingSettings::SPECIALS_ACTIVE`: written at `GuestOrderingSettings.php:227` and shown as `Toggle::make('specials_active')` on `GuestOrdering.php:217`.
   - `ManageCompanySettings::save()` only knows how to write string `SettingsService` keys (lines 270–296, `'string'` type). It has no boolean branch.
   - The existing boolean `allow_shift_start_with_unsettled` is seeded (`SettingsSeeder.php:13`) and read (`User.php:225`, `ReceptionistShiftService.php:49`) but has **no toggle in any UI**.

---

## 10. Files, images, PDFs, QR codes

### 10.1 Disks (`config/filesystems.php`)
| Disk | Root | Served? | Used for |
|---|---|---|---|
| `local` (**default**, `FILESYSTEM_DISK=local` in local `.env`) | `storage/app/private` | `serve => true`. Laravel serves it only with a **valid signed URL** (`vendor/…/Filesystem/ServeFile.php:27–58`: visibility private → `hasValidRelativeSignature()`) | Filament default uploads, **including `users.id_card_copy` and `users.guarantor_form`** and the original company logo |
| `public` | `storage/app/public`, URL `/storage` | needs the `storage:link` symlink, which **`deploy.sh` never creates** (comment in `filesystems.php` at the `menu_photos` disk) | `ExpenseResource.php:69` (receipt photos), product import temp files (`ProductsTable.php:123`) |
| `menu_photos` | `public/media/menu` | yes, directly by the web server | menu item photos (WebP) |
| `branding` | `public/media/branding` | yes, directly | public logo copies |
| `s3` | — | — | unused |

- Filament's `default_filesystem_disk` is `env('FILESYSTEM_DISK', 'local')` (`vendor/filament/support/config/filament.php:44`). The config is not published. **Which disk the staff KYC uploads land on in production depends on the production `.env`. That file was not readable from here.** See Open Question Q14.
- **Menu photos:**
  - Uploaded with `MenuContentFields::photo()` (`app/Filament/Support/MenuContentFields.php:32–60`): disk `menu_photos`, visibility `public`, JPEG/PNG/WebP only, 8 MB max.
  - `saveUploadedFileUsing` → `MenuPhotoProcessor::process()` writes large and thumbnail WebP files and discards the original.

### 10.2 Image processing
- **`intervention/image` 3.11.9**, GD driver, wrapped by `App\Services\MenuPhotoProcessor` (`app/Services/MenuPhotoProcessor.php`):
  - `ImageManager::gd(autoOrientation: true, strip: true)` at lines 57 and 80, so EXIF is stripped.
  - Size-targeted WebP encoding (`encodeNear`, line 124).
  - `assertAcceptable()` (line 101) checks mime type and the 8 MB limit, with a HEIC message.

### 10.3 PDF
- **`barryvdh/laravel-dompdf` 3.1.2** (dompdf 3.1.5). `config/dompdf.php`: `enable_remote => false` (line 270), chroot set to the base path (line 81). Images must be local paths or data URIs.
- Examples:
  - `app/Http/Controllers/FolioReceiptController.php:24–30`: `Pdf::loadView('pdf.folio-receipt', …)->setPaper('a4')->download(...)`.
  - `HandoverPdfController.php:29`.
  - CEO exports in `app/Filament/Ceo/Concerns/ExportsCeoReports.php:38`.
  - **A QR code and a logo embedded in a PDF:** `app/Services/Guest/QrPrintSheets.php:23–80` (`pngDataUri` + `logoDataUri` → `Pdf::loadView`).

### 10.4 QR codes
- **`chillerlan/php-qrcode` 5.0.5.** Wrapped by `App\Services\Guest\QrCodeRenderer` (`app/Services/Guest/QrCodeRenderer.php`):
  - `svg($url)`
  - `png($url, $scale)`
  - `pngDataUri($url)`
  - ECC level M, quiet zone 4, solid white background.
- `bacon/bacon-qr-code` 3.0.3 and `pragmarx/google2fa-qrcode` are also installed, as Fortify 2FA dependencies.

---

## 11. Public route patterns

### 11.1 Guest QR menu
- **Route file:** `routes/guest.php`. It is loaded in `bootstrap/app.php:14–19` **outside the `web` group**, with only `EnsureGuestDevice` and `SubstituteBindings`. That means **no session, no CSRF and no cookie encryption** by design (decision D9).
- **Controller, not Livewire:** `App\Http\Controllers\GuestMenuController`. Pages are one Blade response (`resources/views/guest/menu.blade.php`) plus JSON endpoints. No Livewire or Volt component is used on guest pages.
- Device identity comes from the `selum_gd` cookie (`EnsureGuestDevice`).
- **Rate limits:** named limiters in `AppServiceProvider.php:195–228`: `guest-page`, `guest-poll`, `guest-submit`, `guest-cancel`, `guest-claim`, `guest-call`. Each is limited per device plus per IP, and `guest-submit` also per QR token.
- URLs are built only by `App\Support\GuestUrl` from an opaque `qr_token`. An architecture test enforces this (`tests/Feature/Architecture/GuestUrlSingleSourceTest.php`). The catch-all `/m/{token}` route is last.

### 11.2 Other public forms
| Route | Group | CSRF | Throttle |
|---|---|---|---|
| `POST /kiosk/register` | web | yes | `throttle:10,1` (`routes/web.php:150–152`) |
| `POST /staff/login` | web | yes | `throttle:login` (5/min per email+IP) (`routes/web.php:188–194`) |
| `POST /register` (Fortify) | web | yes | **none** |
| `POST /forgot-password` (Fortify) | web | yes | none configured |
| `POST /iclock/*` | web minus CSRF | **exempt** | none |
| Livewire temporary uploads (not published) | — | — | default `throttle:60,1`, 12 MB, `livewire-tmp` (`vendor/livewire/livewire/config/livewire.php:132–135`) |

- **Honeypot, CAPTCHA or Turnstile: not found** anywhere in `app/`, `config/` or `resources/`.
- `danharrin/livewire-rate-limiting` 2.1.0 is installed, as a Filament dependency.

---

## 12. Notifications

- **The shared helper is `App\Services\UserFeedback`** (`app/Services/UserFeedback.php`):
  - `blocked()`, `failed()` and `succeeded()` wrap `Filament\Notifications\Notification::make()->…->send()`.
  - These are **session flash toasts only**, shown to the current user. They are not stored.
  - Used by admin and kiosk alike, and enforced as the convention by `NoBareAbortTest`.
- **Database notifications:**
  - **Enabled on the admin panel:** `->databaseNotifications()` and `->databaseNotificationsPolling('20s')` (`AdminPanelProvider.php:36, 43`).
  - **Disabled on the CEO panel** (`CeoPanelProvider.php:37`).
  - The `notifications` table exists.
  - **The bell's unread-count badge is hidden by CSS** (`.fi-topbar-database-notifications-btn .fi-icon-btn-badge { display:none !important }`, `AdminPanelProvider.php:137–140`).
- **Pattern for notifying the super admin:** `StaffDebtObserver.php:37–47` queries users by role (`whereHas('roles', whereIn name [manager, admin, super_admin])`), then calls `Notification::make()->…->sendToDatabase($user)`. There are 10 `sendToDatabase` call sites in `app/`.
- Filament's `DatabaseNotification` **implements `ShouldQueue`** (`vendor/filament/notifications/src/DatabaseNotification.php:11`). With `QUEUE_CONNECTION=database`, the notification appears only once a queue worker processes it. `deploy.sh` restarts the queue.
- No `app/Notifications` directory, so no mail or SMS notification classes. Not found.

---

## 13. Data snapshot (COUNTS ONLY)

### 13.1 Production (selum.com.ng, collected 2026-10-16 with read-only tinker counts)

**Users and access**

| Measure | Count |
|---|---|
| Users total | 36 |
| `left_at` set | 0 (nobody has ever been marked as left) |
| No role | 1 (see P1) |
| More than one role | 2 |
| No password | 3 |
| PIN set | 19 |
| Attendance exempt | 0 |
| Placeholder `@attendance.local` emails | 3 |
| Duplicate staff codes | 0 |
| Active trusted phones | 1 |
| Active kiosks | 9 |

**Users per role**

| Role | Users |
|---|---|
| waiter | 9 |
| chef | 5 |
| receptionist | 5 |
| supervisor | 4 |
| ceo | 3 |
| attendance_only | 3 |
| bartender | 2 |
| super_admin | 2 |
| cashier | 1 |
| manager | 1 |
| porter | 1 |
| storekeeper | 1 |
| admin | 0 |

**Fields filled (out of 36)**

| Field | Filled |
|---|---|
| biometric_id | 22 |
| account_number | 21 |
| account_name | 21 |
| bank_name | 20 |
| primary_location | 17 |
| shift_start_time | 15 (legacy ₦500 auto-fine still armed for these) |
| next_of_kin_name | 14 |
| next_of_kin_phone | 10 |
| job_title | 3 |
| id_type | 3 |
| staff_code | 2 |
| id_number | 0 |
| id_card_copy | 0 |
| guarantor_form | 0 |
| base_salary above 0 | 24 |
| has a `staff_salaries` row | 24 |
| has an attendance schedule | 32 |

**Attendance devices**

| Measure | Count |
|---|---|
| ZKTeco device users | 37 |
| Retired | 0 |
| **Unmatched** (active badge with no linked staff) | 15 |
| Punches with no staff attached | 891 |
| Distinct badges behind those punches | 20 |

**Storage:** production `.env` has `FILESYSTEM_DISK=local`. KYC uploads go to the private disk and are served only through signed URLs. This answers Q14 and removes the public-exposure part of R8. The missing type and size validation remains.

**What production adds to the picture**

- **P1. One former staff member was "deactivated" by removing all their roles, and their PIN is still live.**
  - The account has no role, a PIN set, 55 orders and 18 shifts, and was last updated 2026-08-12. It was created 2026-02-13, before user changes were activity-logged.
  - Removing roles is the only deactivation the system currently allows, in practice.
  - `PinAuthService::attempt` does not check roles, so this person can still PIN into a shared kiosk.
  - Deleting the account would cascade away their shifts (Section 7.3).
  - Recommended interim action, which is an operator decision: **Force Reset PIN** on that user.
- **P2. Coverage is a planning input.** Most staff are missing ID number, ID upload, guarantor, staff code and job title. Nobody has `left_at` set. The "complete your profile" path will apply to almost everyone.
- **P3. Fifteen device badges have no linked staff, with 891 punches waiting on 20 badges.** That is consistent with attendance-only workers (cleaners, laundry) not yet created in Selum. If the planned registration path also onboards attendance-only staff, it overlaps with the existing "Create staff & link" flow.
- **P4. Two users hold more than one role, and three hold `ceo`.** Approval-time role assignment needs a rule for whether multiple roles are allowed.
- **P5. Payroll base pay is ₦0 for 15 of the 28 staff payroll currently includes** (Appendix A.2, run 2026-10-16):
  - **8 have no `staff_salaries` row at all.** Payroll resolves them to ₦0.
  - **7 have a row, but it is ₦0.** "Salary on form differs from what payroll uses" was 0, so their form salary is ₦0 too.
  - **All 24 existing rows date from the July one-time copy.** "Rows added after July launch" was 0, so no salary has ever been entered through the app.
  - **Follow-up (Appendix A.2b, run 2026-10-16):**
    - **The 8 with no row all have a salary typed on the user form.** Payroll ignores it, because the form writes `users.base_salary` and payroll reads only `staff_salaries`.
    - **The other 7 have no salary recorded anywhere.**
    - Payroll runs: **3 drafts, 0 sealed, 0 closed.** The latest period end is 2026-07-31.
    - **No payslip has ever been sealed, so nobody has been issued a ₦0 payslip yet.** The gap only bites when the first run is sealed.
  - Fixing it requires a writer for `staff_salaries`. That is a payroll write path, which is on this audit's do-not-touch list, so it needs an explicit go-ahead.

### 13.2 Local dev database

> **Local dev database only.** This is not production.

**Users**
- Total: 8. `left_at` null: 8. `left_at` set: 0.
- No role: 0. More than one role: 0.
- Password null: 0. PIN set: 7.
- `attendance_exempt`: 0. Email verified: 0. 2FA confirmed: 0.

**Users per role**

| Role | Users |
|---|---|
| super_admin | 1 |
| waiter | 2 |
| bartender | 1 |
| chef | 1 |
| manager | 1 |
| porter | 1 |
| receptionist | 1 |
| admin | 0 |
| cashier | 0 |
| ceo | 0 |
| storekeeper | 0 |

`supervisor` and `attendance_only` roles do not exist locally.

**Fields from Section 6 (filled / empty out of 8)**
- email: 8 / 0.
- Every one of these is 0 filled / 8 empty: `staff_code`, `job_title`, `primary_location`, `id_type`, `id_number`, `id_card_copy`, `guarantor_form`, `bank_name`, `account_number`, `account_name`, `next_of_kin_name`, `next_of_kin_phone`, `biometric_id`, `shift_start_time`, `base_salary > 0`.
- Has a `staff_salaries` row: 0 / 8. Has an attendance shift assignment: 0 / 8.

**Devices and attendance**
- ZKTeco device users: 0. Unmatched: 0. Attendance punches with no user: 0.
- Duplicate staff codes: 0.
- Active trusted devices: 0. Active kiosk devices: 1.
- Company rows: 1 (logo not set). Settings rows: 2.

**ZKTeco device users with no matching Selum staff:** this can be determined without writing. It is `attendance_device_users` not retired with no active link (the query is in Appendix A). Locally it is 0, because the terminal has never pushed to this database.

---

## 14. Tests

### 14.1 Users, roles, PIN, user creation
| File | What it asserts |
|---|---|
| `tests/Feature/UserResourceTest.php` | The create page renders with no record; the edit page renders for an existing user and for one with commissions |
| `tests/Feature/UserEditHeavyCommissionsTest.php` | The edit page renders with a long commission history, with flat memory; shows only the 10 most recent; renders with none |
| `tests/Feature/Kiosk/PinAuthServiceTest.php` | PIN is hashed, never plain; exactly 4 digits; trivial PINs rejected; **uniqueness across users**; self-change OK; force-reset is logged; resolves the right user; null on no match; lockout after 5 failures and logged; locked even with the correct PIN; success clears failures; lockouts are per device |
| `tests/Feature/Kiosk/PinSettingsAndForceResetTest.php` | Logged-in user sets their own PIN; trivial and mismatched PINs rejected; super_admin force-resets from the Users resource |
| `tests/Feature/Kiosk/StaffPhoneLoginTest.php` | Form shows with no session; correct password trusts the device **without** a web session; wrong password creates nothing; throttling; redirects when there is no or a revoked device; owner's PIN unlocks the staff route; another person's PIN is refused |
| `tests/Feature/Kiosk/StaffPinGuardIsolationTest.php` | A `staff_pin` session can't open panel pages or resources; `staff_pin.auth` rejects no-session and `web`-only sessions and accepts `staff_pin` |
| `tests/Feature/Kiosk/KioskIdleScreenTest.php` and other `Kiosk/*` tests | Kiosk PIN flow and order attribution |
| `tests/Feature/Attendance/AttendanceOnlyStaffTest.php` | Attendance-only staff are kept out of all panels; can't PIN in with no PIN; conversion keeps the same record; strips other roles; clears credentials; rejects mixed roles; refuses a no-role app user; the role has zero permissions; only super admin changes exemption, and it is logged; `attendance_exempt` can't be mass-assigned; attendance-only staff are not yet in payroll |
| `tests/Feature/Security/PreLaunchSecurityFixesTest.php` | Zero-role user is denied the panel cleanly (false, not a TypeError); one role is enough to get in; PagePermission denies by default |
| `tests/Feature/Security/ShieldRoleEditRendersTest.php` | Shield role edit renders for every role |
| `tests/Feature/Auth/RegistrationTest.php` | **The public `/register` screen renders and new users can register and are authenticated** |
| `tests/Feature/Auth/AuthenticationTest.php`, `EmailVerificationTest.php`, `PasswordResetTest.php`, `PasswordConfirmationTest.php`, `TwoFactorChallengeTest.php` | Fortify starter-kit flows |
| `tests/Feature/Settings/ProfileUpdateTest.php` | Profile update; **"user can delete their account"**; correct password required to delete |
| `tests/Feature/Payroll/PayrollCompilationServiceTest.php:45` | Leavers (`left_at`) are excluded from payroll |
| `tests/Feature/Announcements/AnnouncementServiceTest.php:40`, `Attendance/*` (`ShiftFinaliserTest`, `AttendanceBackfillTest`, `AssignScheduleCommandTest`, `ShiftTemplateAndAssignmentTest`) | Effects of `left_at` on announcements and scheduling |

### 14.2 Architecture tests (`tests/Feature/Architecture/`)
- **The pattern:** plain Pest `it()` tests that scan source files as text, with `File::allFiles` or a `RecursiveDirectoryIterator`, and assert there are no forbidden strings or regex matches. Pest's `arch()` API is installed (`pest-plugin-arch`) but **not used**.
- Most tests include a non-vacuous guard: *"finds the source files it is meant to be guarding"*. Example: `AttendanceBoundariesTest.php:36–40`.
- Examples:
  - `NoBareAbortTest.php`: regex `\babort\s*\(` across `app/` except `Http/Middleware`. **It does not match `abort_unless(` or `abort_if(`**, which are used in `FolioReceiptController.php:21–22` and elsewhere.
  - `AttendanceBoundariesTest.php`: the attendance namespace must not reference debt, payroll, stock or order classes.
  - `GuestUrlSingleSourceTest.php`, `OrderItemSingleCreatorTest.php`, `OrderNumberSingleSourceTest.php`, `FolioLinesAppendOnlyTest.php`, `MenuAvailabilityWritersTest.php`, `StockIntegrityBoundariesTest.php`, `KitchenDeductionSingleEntryTest.php`.
  - In uncommitted work: `OrderStampWriteOnceTest`, `NoOrderDeletionInPaymentPathsTest`, `NoNewSplitPaymentRowsTest`, `PosCancelNeverTouchesReleasedTest`.

---

## 15. Risks & conflicts with the planned direction

**R1 — A public self-registration path already exists, ungated and unthrottled.**
Fortify `/register` creates a roleless `User` and logs them in (`config/fortify.php:147`, `CreateNewUser.php`). From the code, without running it, a roleless account can then:
1. Sign in at `/login` and reach `/dashboard` and `/settings/*` (both are `auth` only).
2. **Set a kiosk PIN** at `/settings/pin`.
3. Pass `/staff/login`, which validates the password with no role check, and get a trusted device.
4. PIN into the `staff_pin` guard at `/staff`.
5. Reach `/staff/order/{table}`, `/staff/notices` and `/staff/report-damage`. **The damage-report submit has no role or shift check** (`kiosk-report-damage.blade.php:72–90` → `DamageReportService::report`).

Ordering itself is blocked, because it needs a shift and the generic shift control is mounted only in the admin topbar (`AdminPanelProvider.php:361`).

Any new "pending application" design must not share this path. Pending applicants must not become authenticatable `users` rows with a password before approval.

**R2 — Hard delete is the only exit, and it damages financial history.**
- No `SoftDeletes`. Two delete paths exist: admin `DeleteAction` (`EditUser.php:455`) and **self-delete at `/settings/profile`**, available to every web user.
- 31 FKs cascade, wiping shifts, commissions, staff debts, stock-ledger rows (and through them procurement items), cash drops, cashier sessions, handover count sessions and items, salary history and fines.
- 17 restrict. When one of those makes the delete fail, the user has already lost all roles, because the Spatie detach runs first with no transaction.
- This directly conflicts with "deactivated, never deleted".

**R3 — `left_at` exists but nothing enforces it, and nothing sets it.**
- No UI or service writes it.
- It does not block panel login, Fortify login, `/staff/login`, kiosk or KDS PIN, PIN-based approvals (handover seal, guest credit), shift start, or trusted devices (Section 7.2).
- About 20 pickers and notification fan-outs ignore it.
- Existing sessions are not ended. Sessions last 120 minutes and `staff_pin` sessions are separate.

**R4 — PIN uniqueness is global, and the error reveals collisions.**
- `PinAuthService::setPin` tells a staff member that their chosen PIN *"is already in use by someone else"* (line 58). Combined with `attempt()` resolving a user from the PIN alone (line 103), whoever sees that message knows a working PIN for another person (kiosk mode, where the device is not bound to one owner).
- The usable space is about 9,976 PINs. A leaver's PIN stays set and stays valid (R3), and keeps blocking reuse.
- If approval sets the PIN, as planned, the approver would choose it. That conflicts with the current rule: *"a manager can force-reset a PIN but never view/choose one"* (CLAUDE.md, `PinAuthService` docblocks).

**R5 — Role assignment bypasses any "only super admin" rule.**
- The `roles` Select is a direct relationship sync (`UserResource.php:157`).
- Anyone with `Update:User` or `Create:User`, which includes the seeded `admin` role, can grant `super_admin` or `ceo`, or approve someone in effect by editing their roles.
- Shield's `super_admin` is not gate-based (`define_via_gate => false`). "Only super admin approves" therefore has to be enforced in code, not through permissions alone.

**R6 — Salary set at approval has nowhere working to go.**
- Payroll reads only `staff_salaries` (`PayrollCompilationService.php:120`).
- That table was filled **once**, by its own migration, in July. Nothing has written to it since.
- The `users.base_salary` field on the user form has been read by nothing since then.
- So every hire since July, including every approved applicant under the new design, gets ₦0 base pay unless the approval writes a `staff_salaries` row.
- **This is a live payroll issue today, separate from registration.** The production check is in Section 13.

**R7 — The staff ID is not permanent today.**
- `users.staff_code` is free text, editable in `UserResource`, and nullable-unique.
- A hard delete frees the code for reuse.
- No sequence or generator exists.
- "Never reused" conflicts with all three.

**R8 — KYC documents: unconstrained uploads, uncertain disk.**
- `id_card_copy` and `guarantor_form` have no type or size validation (`UserResource.php:256–259`).
- They land on Filament's default disk. **Production is `FILESYSTEM_DISK=local`** (Section 13.1), so they are private and served only through signed URLs.
- If `FILESYSTEM_DISK` is ever switched to `public`, they would become guessable `/storage/staff-docs/...` paths.
- Applicant photos on a public form would also need a private disk, unlike menu photos, which go to `public/media`.

**R9 — Personal data is copied into the activity log.**
- `User::getActivitylogOptions` logs all fillable fields except `password` (lines 30–38). Every bank account number, ID number, next-of-kin phone and file path change is written to `activity_log.properties` in plain text.
- Adding address, date of birth, guarantor and ID fields to `$fillable` would extend this automatically.

**R10 — Email is mandatory and unique.**
- `users.email` is NOT NULL and unique, and the form requires it (`UserResource.php:99–103`).
- Attendance-only staff already use fake `@attendance.local` addresses (`DeviceLinkService.php:307`).
- Phone-first applicants without email conflict with this.
- Also, `/staff/login` and panel login are email-based.

**R11 — No phone, address, photo, date of birth, gender, NOK relationship, structured guarantor, state of origin or joined date exist** (Section 6). All are new columns or tables.

**R12 — Notifications are easy to miss.**
- The admin bell's unread badge is hidden by CSS (`AdminPanelProvider.php:137–140`).
- Database notifications are queued, so they depend on a running worker.
- The CEO panel has database notifications off.
- A "new pending application" alert would be invisible without opening the bell.

**R13 — Payroll treatment of leavers.**
- `eligibleStaff()` drops anyone with `left_at` set from the **whole** run, including the period they left in (`PayrollCompilationService.php:61`).
- Attendance-only staff are also excluded from payroll today. `AttendanceOnlyStaffTest.php:174` says "phase 3 will" include them.

**R14 — The ZKTeco legacy penalty still keys off `users.shift_start_time`** (`ZKTecoController`). If approval sets "shift times", it must decide between this legacy field and `attendance_shift_assignments`. Writing the legacy field would re-arm the ₦500 automatic fine.

**R15 — Working-tree state.**
- `config/filament-shield.php` (where custom permissions are declared) is **untracked**.
- The `ShieldSeeder` and `PagePermissionsSeeder` changes are uncommitted.
- The 3 pending migrations (Section 8) mean the local schema is behind the repo.
- Any new permission for approval will touch the same files.

**R16 — `NoBareAbortTest` does not cover `abort_unless` / `abort_if`.** A public verification page written with `abort_unless(...)` would pass the suite but still drop the user on a blank error page.

---

## 16. Open questions to decide before the design is locked

Q1–Q4 are identity and access. Q5–Q8 are deactivation and data safety. Q9–Q13 are applicant data. Q14–Q17 are files and the public pages. Q18–Q20 are approval, notification and existing staff.

1. **Applicant record:**
   - Should a pending application be a separate table that becomes a `User` only on approval?
   - Or a `users` row with a status?
   - With the second option, every existing "any user with a role / any user with a PIN" check (R1, R3) would need to know about pending users.
2. **Fortify `/register`:** keep it, disable it, or replace it with the new form? It is currently on, untested for abuse, and reachable by anyone (R1).
3. **Self-service account deletion at `/settings/profile`:** remove it as part of "never deleted"?
4. **Who may assign roles:**
   - Should role assignment, including on the existing Users form, be restricted to `super_admin` only (R5)?
   - Or only the approval step?
   - Can `admin` keep editing personal details?
5. **Deactivation semantics:**
   - Is `left_at` the deactivation flag, or should there be an explicit status (active / suspended / left) with a reason?
   - Should reactivation be allowed?
   - Should deactivation revoke trusted devices, clear the PIN, end sessions, and end attendance assignments and badge links?
6. **Leaver's final pay:** should someone who leaves mid-period still be in that period's payroll run (R13)?
7. **Historical attribution:** should past reports (CEO, Waiter Ledger, Sales) keep showing leavers by name? Should operational pickers hide them? The design needs a rule for each of the ~20 sites in Section 7.2.
8. **FK cascades:** once deletion is impossible, should the cascading FKs be changed to restrict, as a safety net? That is a schema change on sensitive tables (InventoryTransaction, StaffDebt, commissions, count sessions). Those are on your do-not-touch list.
9. **Staff ID format:**
   - What prefix or pattern (e.g. `SLM-0001`)?
   - Should it be global or per role?
   - What happens to the existing `staff_code` values on production? (Count them with Appendix A.)
   - Should it become non-editable after it is issued?
10. **Email:** optional for applicants? If so, what is the login identifier for staff who need panel access? Is phone the alternative (R10)?
11. **PIN at approval:**
    - The plan says the super admin sets the PIN. That reverses the current "never view/choose a PIN" rule (R4).
    - Keep self-chosen PINs (staff set it on first login), or deliberately change the rule?
    - Either way, should the collision-revealing message be addressed?
12. **Salary:** which store is authoritative, `staff_salaries` (which payroll reads) or `users.base_salary` (which nothing reads)? Should approval write the first `staff_salaries` row (R6)?
13. **Shift times at approval:** an `attendance_shift_assignments` template, or the legacy `users.shift_start_time` that drives the ₦500 auto-fine (R14)?
14. **Applicant files:**
    - Production is `FILESYSTEM_DISK=local`, which is private, and no existing KYC files are uploaded (0 of 36).
    - Should applicant photos and ID scans use that same private disk?
    - Who may view them?
15. **Data retention for rejected applications:** how long to keep their personal data and documents? Hard-delete on rejection, or keep for an audit trail?
16. **Guarantor model:** structured fields (name, phone, address, relationship, occupation) plus an uploaded signed form? One guarantor or more? Is a guarantor required for existing staff too?
17. **Verification page content:**
    - What should the public QR page show (name, photo, role, active/inactive)?
    - Should the URL be unguessable (token) or the staff ID?
    - What should it show for a leaver?
18. **Spam control on the public form:** throttling alone (the existing pattern), or also a honeypot or CAPTCHA? None exists today.
19. **Approval notification:** database notification only, given the badge is hidden (R12)? Or also WhatsApp or email? Who besides super admin should see pending counts?
20. **Existing staff "complete your profile":**
    - Where do they do it: the admin panel, `/settings`, or the kiosk?
    - Does incomplete KYC block anything?
    - How do attendance-only staff, who have no login, complete theirs?

---

## Appendix A — production snapshot queries (read-only, counts only)

> **Do not use `php artisan db` on production.** When it fails to connect, its error message prints the full `mysql` command, **including the database password**. This happened during this audit. Use the tinker commands in A.1 and A.2 instead.

### A.1 Snapshot used for Section 13.1
The tinker one-liner whose output is recorded in Section 13.1. It prints labels and numbers only.

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="\$U='App\Models\User'; \$f=fn(\$c)=>\$U::whereNotNull(\$c)->where(\$c,'!=','')->count(); \$t=\$U::count(); echo 'users total: '.\$t.PHP_EOL.'left_at set: '.\$U::whereNotNull('left_at')->count().PHP_EOL.'no role: '.\$U::doesntHave('roles')->count().PHP_EOL.'more than one role: '.\$U::has('roles','>',1)->count().PHP_EOL.'no password: '.\$U::whereNull('password')->count().PHP_EOL.'pin set: '.\$U::whereNotNull('pin_hash')->count().PHP_EOL.'attendance exempt: '.\$U::where('attendance_exempt',1)->count().PHP_EOL.'placeholder emails: '.\$U::where('email','like','%@attendance.local')->count().PHP_EOL.'--- per role'.PHP_EOL; foreach(Spatie\Permission\Models\Role::withCount('users')->orderBy('name')->get() as \$r){echo \$r->name.': '.\$r->users_count.PHP_EOL;} echo '--- filled (out of '.\$t.')'.PHP_EOL; foreach(['staff_code','job_title','primary_location','id_type','id_number','id_card_copy','guarantor_form','bank_name','account_number','account_name','next_of_kin_name','next_of_kin_phone','biometric_id'] as \$c){echo \$c.': '.\$f(\$c).PHP_EOL;} echo 'shift_start_time: '.\$U::whereNotNull('shift_start_time')->count().PHP_EOL.'base_salary above 0: '.\$U::where('base_salary','>',0)->count().PHP_EOL.'has salary history row: '.\$U::has('salaries')->count().PHP_EOL.'has attendance schedule: '.\$U::has('attendanceShiftAssignments')->count().PHP_EOL.'--- devices'.PHP_EOL.'device users: '.App\Models\Attendance\AttendanceDeviceUser::count().PHP_EOL.'device users retired: '.App\Models\Attendance\AttendanceDeviceUser::whereNotNull('retired_at')->count().PHP_EOL.'device users unmatched: '.App\Models\Attendance\AttendanceDeviceUser::unmatched()->count().PHP_EOL.'punches with no staff: '.App\Models\AttendanceLog::whereNull('user_id')->count().PHP_EOL.'unlinked badges in punches: '.App\Models\AttendanceLog::whereNull('user_id')->distinct()->count('biometric_id').PHP_EOL.'duplicate staff codes: '.\$U::whereNotNull('staff_code')->groupBy('staff_code')->havingRaw('count(*)>1')->pluck('staff_code')->count().PHP_EOL.'trusted phones active: '.App\Models\TrustedDevice::whereNull('revoked_at')->count().PHP_EOL.'kiosks active: '.App\Models\KioskDevice::whereNull('revoked_at')->count().PHP_EOL;"
```

### A.2 Payroll salary-gap check (R6 / P5)
For every staff member payroll would include today, this compares what payroll will use (the `staff_salaries` row) against the user form's `base_salary`. Counts only.

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="\$ex=Spatie\Permission\Models\Role::whereIn('name',['admin','chef','manager','supervisor','waiter','bartender','storekeeper','receptionist','porter','cashier'])->pluck('name')->all(); \$staff=App\Models\User::whereNull('left_at')->role(\$ex)->get(); \$none=0; \$diff=0; \$zero=0; foreach(\$staff as \$u){ \$s=App\Models\StaffSalary::effectiveFor(\$u, now()->toImmutable()); if(\$s===null){\$none++;} elseif((float)\$s->amount<>(float)\$u->base_salary){\$diff++;} if(\$s===null || (float)\$s->amount<=0){\$zero++;} } echo 'staff payroll would include: '.\$staff->count().PHP_EOL.'no salary history row: '.\$none.PHP_EOL.'salary on form differs from what payroll uses: '.\$diff.PHP_EOL.'payroll base pay would be zero: '.\$zero.PHP_EOL.'salary rows added after July launch: '.App\Models\StaffSalary::whereNotNull('created_by')->count().PHP_EOL;"
```

### A.2b Has anyone already been paid ₦0? (read-only, counts only)

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="\$ex=Spatie\Permission\Models\Role::whereIn('name',['admin','chef','manager','supervisor','waiter','bartender','storekeeper','receptionist','porter','cashier'])->pluck('name')->all(); \$staff=App\Models\User::whereNull('left_at')->role(\$ex)->get(); \$typed=0; \$never=0; foreach(\$staff as \$u){ \$s=App\Models\StaffSalary::effectiveFor(\$u, now()->toImmutable()); if(\$s===null && (float)\$u->base_salary>0){\$typed++;} if((\$s===null || (float)\$s->amount<=0) && (float)\$u->base_salary<=0){\$never++;} } echo 'zero pay but salary typed on form: '.\$typed.PHP_EOL.'zero pay and no salary anywhere: '.\$never.PHP_EOL.'--- payroll runs by status'.PHP_EOL; foreach(App\Models\PayrollRun::selectRaw('status, count(*) c')->groupBy('status')->get() as \$r){echo \$r->status.': '.\$r->c.PHP_EOL;} echo 'latest run period end: '.App\Models\PayrollRun::max('period_end').PHP_EOL.'payslips with zero base pay (sealed or closed runs): '.App\Models\PayrollLine::where('base_amount','<=',0)->whereHas('run', fn(\$q)=>\$q->whereIn('status',['sealed','closed']))->count().PHP_EOL.'payslips total (sealed or closed runs): '.App\Models\PayrollLine::whereHas('run', fn(\$q)=>\$q->whereIn('status',['sealed','closed']))->count().PHP_EOL;"
```

### A.3 Equivalent raw SQL (only for a database client that does not echo credentials)
`CHAR(92)` is a backslash, which avoids shell-escaping problems with `App\Models\User`.

```sql
SELECT 'users_total' k, COUNT(*) v FROM users
UNION ALL SELECT 'left_at_null', SUM(left_at IS NULL) FROM users
UNION ALL SELECT 'left_at_set', SUM(left_at IS NOT NULL) FROM users
UNION ALL SELECT 'no_role', COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM model_has_roles m WHERE m.model_id=u.id AND m.model_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'User'))
UNION ALL SELECT 'multi_role', COUNT(*) FROM (SELECT model_id FROM model_has_roles WHERE model_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'User') GROUP BY model_id HAVING COUNT(*)>1) x
UNION ALL SELECT 'password_null', SUM(password IS NULL) FROM users
UNION ALL SELECT 'pin_set', SUM(pin_hash IS NOT NULL) FROM users
UNION ALL SELECT 'attendance_exempt', SUM(attendance_exempt=1) FROM users;

SELECT r.name role, COUNT(m.model_id) users
FROM roles r LEFT JOIN model_has_roles m ON m.role_id=r.id AND m.model_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'User')
GROUP BY r.name ORDER BY r.name;

SELECT 'email' f, SUM(NULLIF(TRIM(email),'') IS NOT NULL) filled, SUM(NULLIF(TRIM(email),'') IS NULL) empty_ FROM users
UNION ALL SELECT 'email_placeholder_attendance_local', SUM(email LIKE '%@attendance.local'), NULL FROM users
UNION ALL SELECT 'staff_code', SUM(NULLIF(TRIM(staff_code),'') IS NOT NULL), SUM(NULLIF(TRIM(staff_code),'') IS NULL) FROM users
UNION ALL SELECT 'job_title', SUM(NULLIF(TRIM(job_title),'') IS NOT NULL), SUM(NULLIF(TRIM(job_title),'') IS NULL) FROM users
UNION ALL SELECT 'primary_location', SUM(NULLIF(TRIM(primary_location),'') IS NOT NULL), SUM(NULLIF(TRIM(primary_location),'') IS NULL) FROM users
UNION ALL SELECT 'id_type', SUM(NULLIF(TRIM(id_type),'') IS NOT NULL), SUM(NULLIF(TRIM(id_type),'') IS NULL) FROM users
UNION ALL SELECT 'id_number', SUM(NULLIF(TRIM(id_number),'') IS NOT NULL), SUM(NULLIF(TRIM(id_number),'') IS NULL) FROM users
UNION ALL SELECT 'id_card_copy', SUM(NULLIF(TRIM(id_card_copy),'') IS NOT NULL), SUM(NULLIF(TRIM(id_card_copy),'') IS NULL) FROM users
UNION ALL SELECT 'guarantor_form', SUM(NULLIF(TRIM(guarantor_form),'') IS NOT NULL), SUM(NULLIF(TRIM(guarantor_form),'') IS NULL) FROM users
UNION ALL SELECT 'bank_name', SUM(NULLIF(TRIM(bank_name),'') IS NOT NULL), SUM(NULLIF(TRIM(bank_name),'') IS NULL) FROM users
UNION ALL SELECT 'account_number', SUM(NULLIF(TRIM(account_number),'') IS NOT NULL), SUM(NULLIF(TRIM(account_number),'') IS NULL) FROM users
UNION ALL SELECT 'account_name', SUM(NULLIF(TRIM(account_name),'') IS NOT NULL), SUM(NULLIF(TRIM(account_name),'') IS NULL) FROM users
UNION ALL SELECT 'next_of_kin_name', SUM(NULLIF(TRIM(next_of_kin_name),'') IS NOT NULL), SUM(NULLIF(TRIM(next_of_kin_name),'') IS NULL) FROM users
UNION ALL SELECT 'next_of_kin_phone', SUM(NULLIF(TRIM(next_of_kin_phone),'') IS NOT NULL), SUM(NULLIF(TRIM(next_of_kin_phone),'') IS NULL) FROM users
UNION ALL SELECT 'biometric_id', SUM(NULLIF(TRIM(biometric_id),'') IS NOT NULL), SUM(NULLIF(TRIM(biometric_id),'') IS NULL) FROM users
UNION ALL SELECT 'shift_start_time', SUM(shift_start_time IS NOT NULL), SUM(shift_start_time IS NULL) FROM users
UNION ALL SELECT 'base_salary_gt_0', SUM(base_salary>0), SUM(base_salary<=0) FROM users
UNION ALL SELECT 'has_staff_salaries_row', SUM(EXISTS(SELECT 1 FROM staff_salaries s WHERE s.user_id=users.id)), SUM(NOT EXISTS(SELECT 1 FROM staff_salaries s WHERE s.user_id=users.id)) FROM users
UNION ALL SELECT 'has_shift_assignment', SUM(EXISTS(SELECT 1 FROM attendance_shift_assignments a WHERE a.user_id=users.id)), SUM(NOT EXISTS(SELECT 1 FROM attendance_shift_assignments a WHERE a.user_id=users.id)) FROM users;

SELECT 'device_users_total' k, COUNT(*) v FROM attendance_device_users
UNION ALL SELECT 'device_users_retired', SUM(retired_at IS NOT NULL) FROM attendance_device_users
UNION ALL SELECT 'device_users_unmatched', COUNT(*) FROM attendance_device_users d
  WHERE d.retired_at IS NULL AND NOT EXISTS (SELECT 1 FROM attendance_device_links l WHERE l.attendance_device_user_id=d.id AND l.voided_at IS NULL AND l.effective_to IS NULL)
UNION ALL SELECT 'attendance_logs_no_user', COUNT(*) FROM attendance_logs WHERE user_id IS NULL
UNION ALL SELECT 'distinct_unlinked_badges', COUNT(DISTINCT biometric_id) FROM attendance_logs WHERE user_id IS NULL
UNION ALL SELECT 'staff_code_duplicates', COUNT(*) FROM (SELECT staff_code FROM users WHERE staff_code IS NOT NULL GROUP BY staff_code HAVING COUNT(*)>1) x
UNION ALL SELECT 'trusted_devices_active', SUM(revoked_at IS NULL) FROM trusted_devices
UNION ALL SELECT 'kiosk_devices_active', SUM(revoked_at IS NULL) FROM kiosk_devices;
```

To check the production upload disk without printing any secret:

```bash
grep -c '^FILESYSTEM_DISK=public' .env   # 1 = public disk, 0 = not public
```
