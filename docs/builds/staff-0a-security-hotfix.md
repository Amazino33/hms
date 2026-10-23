# Staff 0A Build — Security Hotfix

**Brief:** `Build Prompt 0A — Staff Security Hotfix` · **Audit:** `docs/audits/staff-registration-audit.md`
**Base:** `main` @ `eafb9c3` · **Branch:** `hotfix/staff-security` · **Status:** built and tested, not committed, not deployed.

This hotfix closes the live gaps the staff-registration audit found. It builds none of the registration feature itself.

## Decisions taken with the owner before the build

The plan was approved "all as recommended".

| # | Question | Decision |
|---|---|---|
| D1 | Where to declare the two new permissions | `config/filament-shield.php` is created on this branch (see §4). Shield's role editor syncs exactly the permissions it shows (`EditRole.php:53`), so an undeclared permission would be stripped on the next save. |
| D2 | Permission names | `assign_staff_roles`, `deactivate_staff`, as specified |
| D3 | When "last working day" ends | At the close of that business day: 09:00 Lagos the next morning (`BusinessDay`). `left_at` stores that instant, so "deactivated" means `left_at <= now()`. This matches `ShiftScheduleResolver`'s existing reading of `left_at` as the first moment someone is gone. |
| D4 | Future-dated deactivation | Only the fields are written. Every check reads the date, so the person is locked out the moment it passes. The hourly `hms:finalise-deactivations` then clears their PIN, phones and sessions. |
| D5 | Admins creating cleaners | Someone without `assign_staff_roles` may create a brand-new attendance-only person (the role grants nothing). Converting an existing person needs the permission. |
| D6 | Admins and new app users | Admins can only create attendance-only staff. |
| D7 | Deletes that skip the model | Covered by an architecture test (`NoMassUserDeletionTest`). No database trigger. |
| D8 | Extra Deactivate safety | You can't deactivate yourself or the last active super admin. Any open shift is shown in the modal, display only. |

---

## 1. Files changed and created; migrations added

**Created**
- `app/Services/StaffAccessGuard.php` — the two rules: who may change roles, and who may manage `super_admin`/`ceo` accounts
- `app/Services/StaffDeactivationService.php` — deactivate, reactivate, cut-off, finalise, and the read-only debt and open-shift lookups
- `app/Http/Middleware/EnsureAccountIsActive.php`
- `app/Exceptions/StaffDeletionForbiddenException.php`
- `app/Console/Commands/FinaliseDeactivations.php` (`hms:finalise-deactivations`, scheduled hourly)
- `config/filament-shield.php`
- `database/migrations/2026_10_16_090000_add_deactivation_fields_to_users_table.php`
- `docs/builds/staff-0a-security-hotfix.md` (this file)
- Tests: `tests/Feature/Staff/` (8 files), `tests/Feature/Architecture/NoMassUserDeletionTest.php`

**Deleted**
- `app/Actions/Fortify/CreateNewUser.php`
- `resources/views/pages/auth/register.blade.php`
- `resources/views/pages/settings/delete-user-form.blade.php`
- `tests/Feature/Auth/RegistrationTest.php` (replaced by `Staff/RegistrationClosedTest.php`)

**Modified**

| Area | Files |
|---|---|
| Sign-up | `config/fortify.php` (no `Features::registration()`), `app/Providers/FortifyServiceProvider.php` (no register view or action; new `authenticateUsing`), `resources/views/pages/auth/login.blade.php`, `resources/views/welcome.blade.php` (Register links removed) |
| Deletion | `app/Models/User.php` (`booting()` guard), `app/Policies/UserPolicy.php`, `resources/views/pages/settings/profile.blade.php`, `UserResource.php` and `EditUser.php` (delete actions removed) |
| Roles | `UserResource.php`, `Pages/CreateUser.php`, `Pages/EditUser.php`, `Services/Attendance/AttendanceOnlyRoleService.php`, `database/seeders/ShieldSeeder.php` |
| Deactivation | `app/Models/User.php` (`left_at` cast, `scopeActive`, `scopeDeactivated`, `isDeactivated()`, `LEFT_REASONS`), `EditUser.php` (Deactivate / Reactivate), `UserResource.php` (Status column + filter), `routes/console.php` |
| Enforcement | `bootstrap/app.php`, both panel providers, `StaffLoginController.php`, `PinAuthService.php`, `TrustedDeviceService.php`, `ReceptionistShiftService.php`, `BartenderChefShiftService.php` (guard at the top of `startOpeningShift` only) |
| Pickers | `MyCount.php`, `CountSessions.php`, `RoomOrders.php`, `GuestOrdering.php`, `RoomDeliveryService.php`, `DeviceUserResource.php`, `GuestSessionHandoverService.php`, `ShiftManager.php`, `StaffWithoutScheduleWidget.php`, `ShiftAssignmentService.php`, `AssignAttendanceSchedule.php`, `AnnouncementService.php`, `AttendanceDeviceUser.php` |
| PIN | `app/Services/PinAuthService.php` |
| Activity log | `app/Models/User.php` (`SENSITIVE_ATTRIBUTES`, `logExcept`), `app/Observers/UserObserver.php` |
| Tests changed | `tests/Feature/Settings/ProfileUpdateTest.php` (2 delete tests → 1 "no delete option" test) |

**Migration** `2026_10_16_090000_add_deactivation_fields_to_users_table`:
- `users.left_reason` string, nullable
- `users.left_note` text, nullable
- `users.deactivated_by` foreign key to users, `nullOnDelete`

It is additive with no backfill, and every existing user stays active.

---

## 2. Role-assignment paths secured

A grep of `app/` for `assignRole`, `syncRoles`, `removeRole` and role pivot writes finds only these. Seeders are excluded.

| # | Path | How it is secured |
|---|---|---|
| R1 | `UserResource` `roles` Select (create and edit) | Disabled without `assign_staff_roles`, and Filament never saves a disabled relationship field (`BelongsToModel::saveRelationships`). Server side, both pages run in their own DB transaction (`$hasDatabaseTransactions = true`). `EditUser` compares role IDs before (`beforeValidate`) and after the save; `CreateUser` checks after create. Any change without the permission is rolled back with "Only the super admin can change staff roles". |
| R2 | `EditUser` staff-type toggle (attendance-only ↔ app) | Disabled without the permission. `AttendanceOnlyRoleService` re-checks, and a refusal rolls back the whole save. |
| R3 | `CreateUser` → attendance-only | Without the permission, a new person is always attendance-only, whatever was submitted. Allowed for a brand-new record (D5). |
| R4 | `DeviceLinkService::createStaffAndLink` ("Create staff & link") | Allowed for a brand-new record (D5). No code change needed there. |
| R5 | `AttendanceOnlyRoleService::makeAttendanceOnly` / `makeAppUser` | The central check. `makeAppUser` always needs the permission; `makeAttendanceOnly` needs it unless the record was created moments ago with no role. A null actor passes only in a console run (seeders, commands, tests). |
| R6 | Shield role editor | Unchanged: it edits role→permission, not user→role. |

**Protecting `super_admin` and `ceo` accounts** (`StaffAccessGuard::canManage`):
- `UserPolicy::update()` refuses an actor without `assign_staff_roles`, so the edit page returns 403, and with it the password field.
- The table's Edit and Force Reset PIN actions carry `->authorize()`.
- `PinAuthService::forceReset()` re-checks in the service.
- Deactivate and Reactivate check it too.

---

## 3. Enforcement points

| # | Point | Where it lives |
|---|---|---|
| 1 | Panel access, both panels | `User::canAccessPanel()`, checked first, ahead of `super_admin`. Filament's login uses it (`Login.php:127`) and shows its generic failure. |
| 2 | Non-Filament logins | `FortifyServiceProvider` `Fortify::authenticateUsing` ("This account is no longer active.") and `StaffLoginController::login` (no trusted phone is created) |
| 3 | Signed-in sessions | `EnsureAccountIsActive`, on the `web` group (covers every Livewire request) and in both panel stacks. It is in the middleware priority list ahead of `AuthenticatesRequests` (`bootstrap/app.php`); otherwise Filament's `Authenticate` answered a bare 403 and left the session signed in. It checks both `web` and `staff_pin`: logs out, invalidates, redirects with the message. |
| 4 | Kiosk and KDS PIN login | `PinAuthService::attempt()`: a deactivated owner is treated exactly as a wrong PIN and counts towards lockout. Also `TrustedDeviceService::resolveToken()` refuses a deactivated owner's phone. |
| 5 | PIN approvals for someone else | The same `attempt()` covers handover seals (`CountSessionService`, untouched), `ShiftManager:301`, `FastMarkPaidService:199` and `guest-orders-strip`. |
| 6 | Starting a shift | `User::startShift()`, `ReceptionistShiftService::startShift()`, `BartenderChefShiftService::startOpeningShift()`. Handover starts are covered by #5. `CashierSessionService` is deliberately never gated (its own spec); a deactivated cashier can't log in. |
| 7 | Operational pickers and lists (`User::active()`) | `MyCount::candidateIncomingUsers`; `CountSessions` new-session custodians; `RoomOrders` and `GuestOrdering` porters; `RoomDeliveryService` porter check; `DeviceUserResource` link and correction pickers; `GuestSessionHandoverService::handover`; `ShiftManager::handoverCandidates`; `StaffWithoutScheduleWidget`; `ShiftAssignmentService::staffWithoutSchedule`; `AssignAttendanceSchedule`; `AnnouncementService` (audience and `appliesTo`); `AttendanceDeviceUser::suggestedMatch`. The Users list shows a Status column and defaults to Active. |
| 8 | Password reset | The reset itself still works (Fortify's reset never signs anyone in). The login refuses at #1 and #2. |

**Deliberately unchanged** (history, or do-not-touch):
- Activity Log, Attendance Board, `CountSessions` filter, `HandoverDiscrepancies` filter, Shortage Reports, Waiter Ledger (page and service), `OrderResource`, all CEO pages
- `StaffDebtForm`: debts are raised against absconded staff
- The 9 notification fan-outs: a deactivated user can't sign in to read them

---

## 4. Custom-permission pattern

- `config/filament-shield.php` turns on the Custom Permissions tab and lists `assign_staff_roles` and `deactivate_staff`.
- `database/seeders/ShieldSeeder.php` adds both to `super_admin` only. `deploy.sh` step 6 grants that list additively (`givePermissionTo`).
- Code checks them through `StaffAccessGuard` (`$user->can(...)`), never by role name. Granting them to the CEO later is a tick in the Shield role screen.
- **Merge note:** the WIP branch (`wip/order-integrity`) also adds `config/filament-shield.php`. The two copies are identical apart from the `custom_permissions` block (`GrantGuestCredit` there; the two staff permissions here). The merge will conflict on that block only; keep all three entries.

---

## 5. Sensitive values out of the activity log

- `User::SENSITIVE_ATTRIBUTES` lists bank name, account number, account name, ID type, ID number, ID upload, guarantor upload, next-of-kin name and phone, and the three PIN columns.
- `getActivitylogOptions()` excludes them with `logExcept()` (spatie/laravel-activitylog v5, `LogOptions::logExcept`, `vendor/.../Support/LogOptions.php:103`).
- `UserObserver` writes a separate entry such as "Sensitive details updated: bank, ID", or "Sensitive details set: …" on creation. It records the causer and the changed groups, never a value.
- Existing entries are untouched. **Count of existing entries holding sensitive values** is in §7.

---

## 6. Places that could still delete a user or bypass deactivation

- **Raw SQL outside the app:** `php artisan tinker`, a database console or phpMyAdmin can still `DELETE FROM users`. The model guard can't see those, and the architecture test only covers source code.
- **Event-skipping deletes in future code** (`deleteQuietly()`, query-builder deletes) are caught only by `NoMassUserDeletionTest`, at test time.
- **Open shifts:** deactivation doesn't close them. They are shown in the modal and must be settled or closed through the normal shift flow.
- **Session driver:** sessions are deleted immediately only when `SESSION_DRIVER=database` (it is locally). With any other driver, `EnsureAccountIsActive` signs the person out on their next request instead.
- **Filament login message:** a deactivated person sees Filament's generic "credentials do not match" there; the Fortify and `/staff` logins say "This account is no longer active."
- **`shield:generate` for `UserResource`** would regenerate `UserPolicy` and reopen delete and the protected-account rule. The policy's docblock says so. `deploy.sh` does not run it.
- **Anyone holding `Update:Role`** (only `super_admin` today) could tick `assign_staff_roles` onto another role.
- **Payroll (unchanged, as instructed):** `PayrollCompilationService` still uses `whereNull('left_at')`. So someone working out a notice period (future `left_at`) is already left out of a payroll run. That was existing behaviour; it's flagged for the final-pay phase.
- **Notifications still fan out to deactivated users** (9 call sites, listed in the audit §7.2). This doesn't affect security.
- **"Actually belongs to" punch corrections** (`DeviceUserResource`) can't target a deactivated person. Before this change it already excluded anyone with `left_at` set.

---

## 7. Existing activity-log entries holding sensitive values

These entries were not edited or deleted; deciding what to do with them is a separate decision.

| Where | Entries on staff records | Entries anywhere |
|---|---|---|
| Local dev database | **8** | 9 |
| Production | run the command below | run the command below |

Production count. It's read-only, counts only, and has no `!` (the HostAfrica terminal expands it). It was tested locally with `set -H`:

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="\$keys=['bank_name','account_number','account_name','id_type','id_number','id_card_copy','guarantor_form','next_of_kin_name','next_of_kin_phone','pin_hash','pin_lookup_hash','pin_set_at']; \$q=fn()=>Spatie\Activitylog\Models\Activity::query()->where(function(\$w) use (\$keys){ foreach(\$keys as \$k){ \$w->orWhere('attribute_changes','like','%\"'.\$k.'\"%')->orWhere('properties','like','%\"'.\$k.'\"%'); } }); echo 'log entries holding sensitive staff values (staff records): '.\$q()->where('subject_type','App\Models\User')->count().PHP_EOL.'log entries holding sensitive staff values (anywhere): '.\$q()->count().PHP_EOL;"
```

---

## 8. Test results

**New tests: 51, all passing**

| File | Tests |
|---|---|
| `Staff/RegistrationClosedTest` | 3 |
| `Staff/UserDeletionForbiddenTest` | 4 |
| `Staff/RoleAssignmentGuardTest` | 10 |
| `Staff/DeactivationTest` | 8 |
| `Staff/DeactivationEnforcementTest` | 9 (all 8 points plus the notice period) |
| `Staff/ActivePickersTest` | 4 |
| `Staff/PinSetRateLimitTest` | 2 |
| `Staff/SensitiveActivityLogTest` | 3 |
| `Architecture/NoMassUserDeletionTest` | 2. Its patterns were checked against 7 violating and 3 clean samples: all caught, no false positives. |

**Full suite** (`php artisan test --parallel`, PHP 8.4):

| Run | Result |
|---|---|
| 1 | **1,948 passed, 1 skipped**, 0 failed (8,838 assertions) |
| 2 | 1,947 passed, 1 skipped, **1 failed**. The failure is `Kiosk/KioskOrderScreenRedesignTest › never renders an exact stock quantity`. |

- Run 2 came after Pint's unrelated reformatting was taken back out of 6 files (see below).
- **The run-2 failure is a pre-existing flaky test, not a regression.** It asserts the text `173` never appears in the kiosk HTML, and that run Livewire's random component key was `lw-1326917343-0`. The test passed 5 out of 5 reruns on its own, and it doesn't touch any code this hotfix changed. Fixing it is out of scope; it needs a less collision-prone number than `173`.
- **The skip is pre-existing:** `PwaTest.php:131` uses `->skip()`.

**Existing tests changed:**
- `Auth/RegistrationTest`: removed; the feature it tested is gone. `Staff/RegistrationClosedTest` replaces it.
- `Settings/ProfileUpdateTest`: "user can delete their account" and "correct password must be provided to delete account" are replaced by "the profile page offers no way to delete the account".

No other existing test needed changing.

**Lint:** Pint was run on the touched PHP files only. On 6 files that already had style issues (`MyCount`, `StaffLoginController`, `BartenderChefShiftService`, `PinAuthService`, `TrustedDeviceService`, `ShieldSeeder`), it also restyled lines this hotfix doesn't touch. Those files were restored and only the hotfix's edits reapplied. This keeps the diffs small and avoids extra conflicts with the WIP branch, which edits `BartenderChefShiftService` and `ShieldSeeder` too.

---

## 9. Post-deploy steps for production, in order

`deploy.sh` already does the migration, the additive permission grant and the cache rebuild. Push is not deploy: these steps come after the branch is reviewed, merged to `main` and pushed.

**1. Deploy** (HostAfrica terminal):

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && ./deploy.sh
```

This runs:
- `migrate --force`: adds `left_reason`, `left_note` and `deactivated_by`
- step 6: creates `assign_staff_roles` and `deactivate_staff` and gives them to `super_admin`, additively
- the PagePermissions seeder
- `config:cache`: picks up the new `config/filament-shield.php`
- `route:cache` and `view:cache`
- `queue:restart`

**2. Verify** (read-only, no `!`):

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="foreach(['assign_staff_roles','deactivate_staff'] as \$p){ \$perm=Spatie\Permission\Models\Permission::where('name',\$p)->first(); echo \$p.' held by: '.(\$perm ? \$perm->roles()->pluck('name')->join(', ') : 'NOT CREATED').PHP_EOL; } echo 'deactivation columns present: '.(Illuminate\Support\Facades\Schema::hasColumns('users',['left_reason','left_note','deactivated_by']) ? 'yes' : 'NO').PHP_EOL.'register route present: '.(Illuminate\Support\Facades\Route::has('register') ? 'YES - PROBLEM' : 'no').PHP_EOL.'finalise command scheduled: '.(collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn(\$e)=>str_contains((string) \$e->command,'hms:finalise-deactivations')) ? 'yes' : 'NO').PHP_EOL;"
```

Expected output:
- both permissions held by `super_admin`
- columns `yes`
- register route `no`
- scheduled `yes`

**3. Check how sessions are stored** (decides whether deactivation ends sessions instantly or on the next request):

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && grep SESSION_DRIVER .env
```

**4. Deactivate the former staff member found in the audit** (the role-less account with a live PIN):
- Go to Admin → Users and set the Status filter to show all.
- Open the person with no role badge and choose **Deactivate**.
- Use their real last working day and a reason.

This clears their PIN and records why they left, replacing the earlier interim "Force Reset PIN" advice.

**5. Count the existing sensitive log entries** (§7) and decide separately what to do with them.

**Nothing to run by hand for the hourly job:** `hms:finalise-deactivations` rides on the same `schedule:run` cron as `attendance:finalise`.

---

## 10. Observed, not changed

- **Pre-existing flaky test:** `KioskOrderScreenRedesignTest` (see §8).
- **Payroll and notice periods:** `PayrollCompilationService::eligibleStaff()` leaves out anyone with `left_at` set, including someone still working a notice period. It was left untouched as instructed; this belongs to the final-pay phase.
- **Badge link and punch-correction pickers:** `DeviceUserResource`'s link and correction pickers now use `active()`, so they include notice-period staff. They still can't target someone already gone, as before.
- **The Count Breakdown migration** (`2026_10_08_100000`) is still pending locally, as agreed; this branch doesn't touch it. **This hotfix's own migration was not run on the local MySQL database either.** The tests use their own in-memory database.
