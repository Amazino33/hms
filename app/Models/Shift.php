<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'ended_at',
        'status',
        'declared_cash',
        'declared_pos',
        'supervisor_confirmed_cash',
        'supervisor_confirmed_pos',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'declared_cash' => 'decimal:2',
        'declared_pos' => 'decimal:2',
        'supervisor_confirmed_cash' => 'decimal:2',
        'supervisor_confirmed_pos' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    // Check if shift is currently active
    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    // Get shift duration in minutes
    public function getDurationAttribute(): ?int
    {
        if (!$this->ended_at) return null;
        
        return $this->started_at->diffInMinutes($this->ended_at);
    }

    // Scope for active shifts
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    // Scope for completed shifts
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('ended_at');
    }
}
