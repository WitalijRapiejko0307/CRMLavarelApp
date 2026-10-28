<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Failure while building a Belpost blank in CRM. `code` is a stable contract for the UI and logs.
 */
class BelpostLabelException extends RuntimeException
{
    public const SENDER_MISSING    = 'sender_missing';
    public const NO_TRACKED_ORDERS = 'no_tracked_orders';
    public const PDF_RENDER_FAILED = 'pdf_render_failed';
    public const UNKNOWN           = 'unknown';

    private string $errorCode;

    public function __construct(string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
