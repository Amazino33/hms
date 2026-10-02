<?php

namespace App\Services\Guest;

/**
 * D19: the first acceptance of a sitting found older unpaid orders on the
 * table, and nobody has said yet whether they belong to these guests. The
 * kiosk asks "Same guests?" and tries again with the answer.
 */
class SameGuestsQuestion extends \Exception
{
    public function __construct(public readonly float $unpaid, public readonly string $tableName)
    {
        parent::__construct($tableName.' already has ₦'.number_format($unpaid).' unpaid. Same guests?');
    }
}
