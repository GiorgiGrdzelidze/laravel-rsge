<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * The waybill service refused an operation with one of its own error codes.
 *
 * errorCode holds the rs.ge code (e.g. -1036), errorDescription its text as rs.ge words it.
 */
class RsgeWaybillException extends RsgeException
{
    /**
     * @param  list<array{line: int, code: int, text: ?string}>  $lineErrors  Errors rs.ge reported per goods line.
     */
    public function __construct(
        string $message = '',
        ?int $errorCode = null,
        ?string $errorDescription = null,
        public readonly array $lineErrors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $errorDescription, $previous);
    }
}
