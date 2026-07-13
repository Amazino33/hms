<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A managed reference list of unit names (bottle, crate, pack, carton...)
 * used by the Product form's base/purchase unit selects, so storekeepers
 * pick from a consistent set instead of free-typing variants of the same
 * word ("Crate" vs "crate" vs "Crates").
 */
class Unit extends Model
{
    protected $guarded = [];
}
