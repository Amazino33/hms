<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown by User's own deleting listener. A staff record is referenced by
 * 111 foreign keys: 31 of them cascade (shifts, commissions, debts, stock
 * ledger rows) and 17 restrict, so a delete either wipes financial history
 * or fails half-way. Staff who leave are deactivated through
 * StaffDeactivationService instead.
 */
class StaffDeletionForbiddenException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Staff records cannot be deleted. Deactivate the staff member instead.');
    }
}
