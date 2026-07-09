<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Models\Order;
use App\Services\ShiftAccountingService;
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
     * Start a new shift for the user
     */
    public function startShift(): Shift
    {
        // End any existing active shift first
        if ($currentShift = $this->currentShift()) {
            $currentShift->update([
                'ended_at' => now(),
                'status' => 'closed',
            ]);
        }

        return $this->shifts()->create([
            'type' => $this->shiftTypeFromRole(),
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
                    "{$role} shifts can only end through a confirmed count — use My Handover Count to hand over to someone, or to close for the day if nobody's taking over, not this control."
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
                'status' => 'pending_supervisor',
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

    public function canAccessPanel(Panel $panel): bool
    {
        // Allow super admins unconditionally
        if ($this->hasRole('super_admin')) {
            return true;
        }

        // Allow any user with at least one role
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
