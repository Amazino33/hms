<?php

namespace App\Exceptions;

class PinLockedException extends \Exception
{
    public function __construct(public readonly ?int $lockedUntilTimestamp)
    {
        $seconds = $lockedUntilTimestamp ? max(0, $lockedUntilTimestamp - now()->timestamp) : 0;

        parent::__construct("Too many failed PIN attempts. Try again in {$seconds} seconds.");
    }
}
