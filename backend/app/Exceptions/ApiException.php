<?php

namespace App\Exceptions;

/**
 * Throw this with one of the fixed error codes from
 * docs/MASTER_PROMPT.md section 5 to get the standard error envelope
 * rendered automatically (see bootstrap/app.php withExceptions).
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
