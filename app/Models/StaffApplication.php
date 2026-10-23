<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application from the public /join form. Only StaffApplicationService
 * writes these: submit() creates, approve()/reject() decide. Nothing on this
 * model can authenticate — the user account is created at approval.
 *
 * Deliberately no LogsActivity: the service writes its own entries (submitted,
 * approved, rejected) with no personal values in them.
 */
class StaffApplication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    /** Means of ID accepted on the form; the key is what is stored. */
    public const ID_TYPES = [
        'nin' => 'NIN',
        'voters' => "Voter's card",
        'drivers_licence' => "Driver's licence",
        'intl_passport' => 'International passport',
    ];

    /**
     * Everything personal an application holds. Cleared on rejection; the
     * name and dates stay as the record that someone applied.
     */
    public const PERSONAL_FIELDS = [
        'phone', 'email', 'address', 'photo_path', 'password_hash',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
        'bank_name', 'account_number', 'account_name',
        'guarantor_name', 'guarantor_phone', 'guarantor_address', 'guarantor_relationship', 'guarantor_occupation', 'guarantor_form_path',
        'id_type', 'id_number', 'id_document_path',
    ];

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY: a database dump alone does not
            // reveal them.
            'account_number' => 'encrypted',
            'id_number' => 'encrypted',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
