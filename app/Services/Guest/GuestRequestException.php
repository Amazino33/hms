<?php

namespace App\Services\Guest;

/**
 * A guest-request refusal with a message written for the guest, plus the
 * machine code and HTTP status the JSON endpoint answers with — the guest
 * pages never show a raw error.
 */
class GuestRequestException extends \Exception
{
    /**
     * @param  array<string, mixed>  $context  extra JSON for the page (e.g. which item sold out)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
