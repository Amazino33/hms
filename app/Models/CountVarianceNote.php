<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * A count participant's explanation for one item's variance. Append-only:
 * a second thought is a second note, never an edit.
 */
class CountVarianceNote extends Model
{
    use AppendOnly;

    protected $guarded = [];

    public function line()
    {
        return $this->belongsTo(CountBreakdownLine::class, 'count_breakdown_line_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
