# Staff 0C Build — Staff Application Form

**Audit:** `docs/audits/staff-registration-audit.md` · **Base:** `main` @ `685655f` (with the 0A security hotfix)
**Branch:** `feature/staff-applications` · **Status:** built and tested, not committed, not deployed.

Staff apply on their phone at `/join`. Each application waits as **Pending** with no login. The super admin approves or rejects it. Approval creates the staff account with a permanent staff ID.

The old Fortify `/register` stays closed: it created a live account with no approval, which is the hole 0A shut.

## Decisions (approved "all as recommended")

| # | Decision |
|---|---|
| Q1 | Applications live in their own table (`staff_applications`). No user row, and so no login, exists until approval. |
| Q2 | The applicant chooses their password on the form. Only its bcrypt hash is kept, and approval hands that hash to the new account (Laravel's `hashed` cast stores an existing hash as is). Nobody else ever knows it. They set their own kiosk PIN after first login. |
| Q3 | The staff ID is `SLM-` plus 4 digits, issued in order at approval. It can't be edited (`User::booting()` refuses a change, and the Users form shows it read-only) and is never reused (users are never deleted). Free-text codes typed in before this existed don't affect the sequence. |
| Q4 | No salary at approval. That comes with 0B. |
| Q5 | Attendance-only staff can apply. At approval you choose App user or Attendance only; attendance-only gets no login and the password they chose is discarded. |
| Q6–Q7 | ID card PDF, QR verification page and "complete your profile" are later phases. |
| Q8 | No date of birth, gender or state of origin on the form. |

**One deviation from the plan:** the open/close switch is on the **Staff Applications** screen, next to the applications and the link, rather than in Company Settings. It's stored the same way (`SettingsService`, key `staff_applications_open`).

---

## 1. What was built

### Public form — `/join`
- `resources/views/pages/join.blade.php` (Livewire page) with `resources/views/layouts/join.blade.php` (public layout showing the venue name and logo from Company Settings).
- Route `staff.join` in `routes/web.php`, limited to 30 page loads a minute (`throttle:30,1`).
- **Sections:** about you (with passport photo), login password, next of kin, bank, guarantor (with signed form), means of ID (with document). All required.
- **Closed:** while applications are switched off, the page says "Applications are currently closed", and a submission is refused in both the page and the service.
- **Spam limits:**
  - 10 submissions per network address per hour (generous, because staff on the venue Wi-Fi share one address)
  - 3 per email per hour
  - a hidden trap field: a bot that fills it gets a fake success and nothing is stored
- **Duplicates:** an email that already belongs to a staff account, or to a pending application, is refused.
- **Uploads:**
  - All files go on the **private** `local` disk, under `staff-applications/{reference}/`, never under `public/`.
  - Photos (and photos of documents) are re-encoded through `MenuPhotoProcessor`, which strips EXIF data, including the GPS position a phone camera records.
  - A PDF is kept as uploaded.
  - Limit is 8 MB per file. An iPhone HEIC photo gets the existing "choose again from Photos" message.

### Review screen — Staff Applications (admin panel, User Management group)
- `StaffApplicationResource` with list and view pages. It is read-only: no create, edit or delete.
- Navigation badge shows the number pending.
- The list defaults to Pending and shows whether applications are **open or closed**, with the link to send. Super admin has **Open applications** and **Close applications** buttons.
- The view page shows everything. The photo appears through a signed link; the ID and guarantor form open through a **15-minute signed link**.
- **Approve** asks for staff type, role(s) and an optional job title, then opens the new staff record.
- **Reject** needs a reason.
- **New application notice:** each super admin gets a bell notification. Its counter is still hidden by the existing CSS, but the navigation badge is visible.

### Services
| File | Does |
|---|---|
| `StaffApplicationService` | `submit()`, `approve()`, `reject()`. The only writer of applications, and `approve()` is the only way one becomes a user. |
| `StaffApplicationSettings` | The open/closed switch |
| `StaffIdGenerator` | Next `SLM-` ID (rows locked inside the approval transaction), and `isIssued()` |
| `StaffDocumentStore` | Private storage, re-encoding, deletion |
| `App\Support\NigerianBanks` | The bank list, moved out of `UserResource` so both forms share it |

**Approval** runs in one transaction. It:
- locks the application and refuses one that's already decided
- re-checks the email is still free
- creates the user (name, email, password hash or none, staff ID, phone, address, photo, next of kin, bank, ID, document paths)
- gives the role, or makes the person attendance-only
- writes a `staff_guarantors` row
- marks the application approved, clearing its copy of the password hash and the encrypted numbers
- logs "Staff application APP-… approved", with the staff ID and role but no personal values

**Rejection** clears every personal field (`StaffApplication::PERSONAL_FIELDS`), then deletes the files after the commit. It keeps the name, dates and reason, and logs "… rejected".

### Data
| Migration | Adds |
|---|---|
| `2026_10_23_090000_create_staff_applications_table` | Applications. `account_number` and `id_number` are **encrypted at rest** (`encrypted` cast). |
| `2026_10_23_090100_create_staff_guarantors_table` | One row per guarantor, so existing staff can be given one later |
| `2026_10_23_090200_add_contact_fields_to_users_table` | `users.phone`, `address`, `photo_path`, `next_of_kin_relationship`. Nullable and additive. |

### Permissions
- `approve_staff_applications` (custom; it's in `config/filament-shield.php` so the Shield editor can't drop it), plus `ViewAny:StaffApplication` and `View:StaffApplication`.
- All three are in `ShieldSeeder` for `super_admin` only; `deploy.sh` grants them additively.
- Approving as an **App user** also needs `assign_staff_roles` (0A), which `super_admin` already holds.
- `StaffApplicationPolicy` denies create, update and delete to everyone.

### Changes to existing files
| File | Change |
|---|---|
| `app/Models/User.php` | `phone`, `address`, `next_of_kin_relationship` are fillable. `guarantors()` and `staffApplication()` relations added. `SENSITIVE_ATTRIBUTES` gains phone, address, photo and next-of-kin relationship, so they're logged as "changed" without values. The `updating` guard makes an issued staff ID permanent. |
| `UserResource.php` | Staff ID is read-only once issued. ID types gain driver's licence and international passport. Staff phone, home address and next-of-kin relationship are on the Emergency tab. The bank list comes from `NigerianBanks`. |
| `routes/web.php` | The `/join` route |
| `config/filament-shield.php`, `database/seeders/ShieldSeeder.php` | The three permissions |

**Front-end assets:** `npm run build` produced byte-identical files, because every class the form uses was already in the shipped CSS. Nothing in `public/build` changed.

---

## 2. Tests

All 19 new tests pass (181 assertions):
- `tests/Feature/StaffApplications/JoinFormTest.php`: 10
- `tests/Feature/StaffApplications/ApprovalTest.php`: 9

They cover:
- reachable without a login
- closed state refuses
- Pending application and no user account created
- only a password hash is kept
- files on the private disk, re-encoded
- encryption at rest
- duplicate email (existing staff, and a pending application)
- form validation
- hidden trap field
- per-network limit
- super admin notification
- no personal values in the log
- PDF documents
- approval creates the account (staff ID, own password, role, details, guarantor, no PIN) and clears the application's copies
- IDs in order and never reused, unaffected by old free-text codes
- issued ID permanent, server-side and on screen
- attendance-only has no login
- the approved person signs in with their own password and sets their own PIN
- no deciding twice, and a re-check of an email taken in the meantime
- rejection clears every personal field and file and keeps the record
- admin refused, on screen (403) and in the service
- list, badge, link and the open/close switch

**Full suite:** see the result in the hand-over message. No existing test needed changing.

---

## 3. Production steps

**1. Deploy.** Push first; deploying is a separate step.

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && ./deploy.sh
```

This runs the 3 migrations, grants the permissions and rebuilds the caches.

**2. Check** (read-only, no `!`):

```bash
cd /home/selumcom/domains/selum.com.ng/hms_core && php artisan tinker --execute="foreach(['approve_staff_applications','ViewAny:StaffApplication','View:StaffApplication'] as \$p){ \$perm=Spatie\Permission\Models\Permission::where('name',\$p)->first(); echo \$p.' held by: '.(\$perm ? \$perm->roles()->pluck('name')->join(', ') : 'NOT CREATED').PHP_EOL; } echo 'applications table: '.(Illuminate\Support\Facades\Schema::hasTable('staff_applications') ? 'yes' : 'NO').PHP_EOL.'guarantors table: '.(Illuminate\Support\Facades\Schema::hasTable('staff_guarantors') ? 'yes' : 'NO').PHP_EOL.'new user columns: '.(Illuminate\Support\Facades\Schema::hasColumns('users',['phone','address','photo_path','next_of_kin_relationship']) ? 'yes' : 'NO').PHP_EOL.'join page: '.route('staff.join').PHP_EOL.'applications open: '.(App\Services\StaffApplicationSettings::isOpen() ? 'yes' : 'no (open them from Staff Applications)').PHP_EOL.'existing SLM staff IDs: '.App\Models\User::where('staff_code','like','SLM-%')->count().PHP_EOL;"
```

Expected: all three permissions held by `super_admin`, tables and columns `yes`, and the join page shown as `https://selum.com.ng/join`.

**3. Open applications.** In Admin → Staff Applications, press **Open applications**, then send `https://selum.com.ng/join`.

**4. Close them again** when you've finished hiring.

---

## 4. Known limits and follow-ups

- **No salary at approval.** Set salaries through 0B once it's built. The old salary box on the user form is still the one payroll ignores, so don't rely on it.
- **The bell counter is hidden** by the existing admin CSS. Rely on the navigation badge.
- **Applicants' own copy:** a form's values stay in the applicant's browser until it's submitted, as with any web form. After submitting, the form is cleared.
- **No "check my status" page** for applicants. That's deliberate, to avoid an enumeration oracle; the manager contacts them.
- **ID card PDF, QR verification and "complete your profile"** are later phases. Staff IDs issued now carry onto the cards.
