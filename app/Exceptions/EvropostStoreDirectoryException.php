<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside Cache::remember so a failed Europochta directory fetch is not stored.
 */
class EvropostStoreDirectoryException extends RuntimeException
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        parent::__construct($payload['message'] ?? 'Evropochta stores failed');
    }
}
