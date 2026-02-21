<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
            'started_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * End the current shift
     */
    public function endShift(): ?Shift
    {
        $shift = $this->currentShift();
        if ($shift) {
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
        return $this->belongsTo(\App\Models\Warehouse::class);
    }

    /**
     * Commissions earned by this waiter.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(\App\Models\Commission::class);
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
    }
}
