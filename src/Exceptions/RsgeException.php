<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * Base exception for all rs.ge API errors.
 */
class RsgeException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $errorCode = null,
        public readonly ?string $errorDescription = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
