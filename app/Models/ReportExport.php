<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A log entry only — recording an export never triggers or depends on
 * snapshot recomputation.
 */
class ReportExport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'range_start' => 'date',
        'range_end' => 'date',
        'exported_at' => 'datetime',
    ];

    public function exportedBy()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public static function record(string $report, string $format, string $rangeStart, string $rangeEnd, int $userId): self
    {
        return static::create([
            'exported_by' => $userId,
            'report' => $report,
            'format' => $format,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'exported_at' => now(),
        ]);
    }
}
