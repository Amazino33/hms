<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when code tries to update or delete a row in an append-only
 * table (the count breakdown snapshot and its notes). Corrections there
 * are new rows that supersede old ones, never an edit in place.
 */
class AppendOnlyViolation extends LogicException {}
