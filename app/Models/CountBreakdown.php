<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Header of a count's frozen movement breakdown: the window it covers and
 * who counted/signed, copied in at lock time. See
 * CountBreakdownSnapshotService.
 */
class CountBreakdown extends Model
{
    use AppendOnly;

    protected $guarded = [];

    protected $casts = [
        'window_from' => 'datetime',
        'window_to' => 'datetime',
        'counted_at' => 'datetime',
        'reconstructed_at' => 'datetime',
    ];

    public function isReconstructed(): bool
    {
        return $this->reconstructed_at !== null;
    }

    public function session()
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }

    public function previousSession()
    {
        return $this->belongsTo(CountSession::class, 'previous_count_session_id');
    }

    public function lines()
    {
        return $this->hasMany(CountBreakdownLine::class);
    }

    /**
     * A correction is a new line pointing at the one it replaces, so the
     * line in force is whichever one nothing else supersedes.
     */
    public function currentLines()
    {
        return $this->lines()->whereNotIn('id', function ($q) {
            $q->select('supersedes_id')->from('count_breakdown_lines')->whereNotNull('supersedes_id');
        });
    }
}
