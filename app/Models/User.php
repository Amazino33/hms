<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Models\Order;
use App\Services\ShiftAccountingService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles, LogsActivity;

    /**
     * Never log credentials or secrets, even hashed — only the fields an
     * admin would actually be editing (name, contact/bank/KYC details).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password'])
            ->logOnlyDirty()
            ->useLogName('user')
            ->dontLogEmptyChanges();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'staff_code',
        'primary_location',
        'id_type',
        'id_number',
        'id_card_copy',
        'guarantor_form',
        'base_salary',
        'bank_name',
        'account_number',
        'account_name',
        'next_of_kin_name',
        'next_of_kin_phone'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'pin_hash',
        'pin_lookup_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the user's shifts
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Get the user's current active shift
     */
    public function currentShift()
    {
        return $this->shifts()->active()->first();
    }

    /**
     * Check if user is currently on shift
     */
    public function isOnShift(): bool
    {
        return $this->currentShift() !== null;
    }

    /**
     * Start a new shift for the user.
     *
     * Three guards, deliberately different in shape:
     *  - Already on an active shift of the SAME type being requested:
     *    return it unchanged (idempotent) — this used to force-close the
     *    still-open shift straight to 'closed', bypassing settlement
     *    entirely (no expected-cash calc, no shortfall debt, ever, for
     *    real losses on that shift). A double-click/page-refresh calling
     *    this twice must never destroy settlement data.
     *  - Already on an active shift of a DIFFERENT type (e.g. clocked in
     *    as a waiter, now trying to start a receptionist shift): blocked
     *    outright — silently returning the wrong-typed shift would ignore
     *    what was actually requested, and silently force-closing it is
     *    the exact data-destroying bug above. Must end the current shift
     *    through its own proper flow first.
     *  - A prior shift is 'awaiting_cashier' (submitted, not yet
     *    confirmed): blocked outright unless the
     *    allow_shift_start_with_unsettled setting is on, in which case
     *    this proceeds and the caller is responsible for showing the
     *    warning banner (Shift::hasUnsettledFor() is cheap to re-check
     *    from the UI layer for that).
     *
     * @throws \Exception
     */
    public function startShift(): Shift
    {
        $requestedType = $this->shiftTypeFromRole();

        if ($currentShift = $this->currentShift()) {
            if ($currentShift->type === $requestedType) {
                return $currentShift;
            }

            throw new \Exception("You have an active {$currentShift->type} shift — end it before starting a {$requestedType} shift.");
        }

        // Bartender/chef shifts only ever start through a reviewed opening
        // count or a declared handover (BartenderChefShiftService) — never
        // this generic control. Without this block, this path bypassed the
        // entire single-custodian handover system: it doesn't check whether
        // another bartender/chef already holds the shift, doesn't require
        // any count session at all, and let two people show active for the
        // same role at once with nothing to reconcile between them.
        if (in_array($requestedType, ['bartender', 'chef'], true)) {
            $role = ucfirst($requestedType);

            throw new \Exception(
                "{$role} shifts can only start through a reviewed opening count or a declared handover — use My Handover Count, not this control."
            );
        }

        // Same class of gap as bartender/chef above, minus the physical-
        // stock handover: a receptionist's accountability is a cash float
        // (ReceptionistShiftService::startShift() records it), which this
        // generic path has no field for at all. Without this block, a
        // receptionist could start here with no float recorded, silently
        // understating expectedCashRemittance() by that exact amount at
        // settlement time. The front desk is also single-custodian (one
        // receptionist at a time) — that check lives in
        // ReceptionistShiftService::startShift() itself, since it needs to
        // inspect OTHER users' shifts, not just this one's.
        if ($requestedType === 'receptionist') {
            throw new \Exception(
                'Receptionist shifts start from the Receptionist Shift page, where your starting cash float is recorded — use that, not this control.'
            );
        }

        if (Shift::hasUnsettledFor($this->id) && ! SettingsService::getBool('allow_shift_start_with_unsettled')) {
            throw new \Exception('Your last settlement is awaiting cashier confirmation and must be resolved before you can start a new shift.');
        }

        return $this->shifts()->create([
            'type' => $requestedType,
            'started_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * The generic "Start Shift" control (topbar ShiftManager) never asks
     * which role someone is clocking in as — without this, every shift
     * silently fell back to the shifts table's default ('waiter'), even
     * for an actual bartender/chef. That meant OrderSplitter's bar/kitchen
     * order guard (which checks for an active bartender/chef-typed shift,
     * not just any shift) could never be satisfied through the normal
     * clock-in flow — bartenders and chefs would clock in, but every bar
     * or kitchen order would still be rejected as if nobody was on duty.
     */
    private function shiftTypeFromRole(): string
    {
        if ($this->hasRole('bartender')) {
            return 'bartender';
        }

        if ($this->hasRole('chef')) {
            return 'chef';
        }

        if ($this->hasRole('receptionist')) {
            return 'receptionist';
        }

        return 'waiter';
    }

    /**
     * End the current shift
     */
    public function endShift(): ?Shift
    {
        $shift = $this->currentShift();
        if ($shift) {
            if (in_array($shift->type, ['bartender', 'chef'], true)) {
                $role = ucfirst($shift->type);
                throw new \Exception(
                    "{$role} shifts can only end through a declared, dual-PIN-sealed handover count — use My Handover Count, not this control."
                );
            }

            if ($shift->type === 'receptionist') {
                throw new \Exception(
                    'Receptionist shifts end through the Receptionist Shift page (declares cash/POS totals), not this control.'
                );
            }

            $outstanding = (new ShiftAccountingService())->outstandingOrders($shift);

            if ($outstanding->isNotEmpty()) {
                $count = $outstanding->count();
                throw new \Exception(
                    "You have {$count} unpaid order(s) that must be paid, returned, or resolved by a supervisor before you can end your shift."
                );
            }

            $pendingReturns = (new ShiftAccountingService())->pendingReturns($shift);

            if ($pendingReturns->isNotEmpty()) {
                $count = $pendingReturns->count();
                throw new \Exception(
                    "You have {$count} return(s) still awaiting bar/kitchen confirmation — they must be confirmed or rejected before you can end your shift."
                );
            }

            $endedAt = now();

            $orders = Order::query()
                ->where('user_id', $this->id)
                ->whereBetween('created_at', [$shift->started_at, $endedAt])
                ->whereIn('status', ['paid', 'partial'])
                ->selectRaw('COALESCE(SUM(paid_cash), 0) as paid_cash')
                ->selectRaw('COALESCE(SUM(paid_pos), 0) as paid_pos')
                ->first();

            $shift->update([
                'ended_at' => $endedAt,
                'status' => 'awaiting_cashier',
                'declared_cash' => (float) ($orders->paid_cash ?? 0),
                'declared_pos' => (float) ($orders->paid_pos ?? 0),
            ]);
        }
        return $shift;
    }

    /**
     * Get the user's assigned warehouse
     */
    public function warehouse()
    {
        return $this->belongsTo(\App\Models\WareHouse::class);
    }

    /**
     * Commissions earned by this waiter.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(\App\Models\Commission::class);
    }

    /**
     * Staff debts owed by this user (shortfalls, converted unpaid orders, etc.).
     */
    public function debts(): HasMany
    {
        return $this->hasMany(\App\Models\StaffDebt::class);
    }

    /**
     * Effective-dated base salary history for this user.
     */
    public function salaries(): HasMany
    {
        return $this->hasMany(\App\Models\StaffSalary::class);
    }

    /**
     * Payroll lines (per-run payslips) for this user.
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(\App\Models\PayrollLine::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Allow super admins unconditionally, on every panel.
        if ($this->hasRole('super_admin')) {
            return true;
        }

        // The CEO panel is a separate, read-only surface — only the ceo
        // role (and super_admin, above) may ever enter it, regardless of
        // what other roles a user holds.
        if ($panel->getId() === 'ceo') {
            return $this->hasRole('ceo');
        }

        // A ceo-only user must never fall through to the generic "any role"
        // grant below — that would hand them the operational admin panel,
        // which the CEO module explicitly must never expose.
        if ($this->hasRole('ceo')) {
            return false;
        }

        // Allow any (non-ceo) user with at least one role.
        if ($this->roles()->exists()) {
            return true;
        }

        // A user with zero roles must be denied cleanly, not fall through
        // to an implicit null — this method is typed ": bool", and PHP
        // throws a TypeError on a null return here, which (combined with
        // APP_DEBUG) would leak a stack trace instead of a clean denial.
        return false;
    }
}
