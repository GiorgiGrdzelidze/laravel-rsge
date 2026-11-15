<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * Thrown when rs.ge rejects the supplied credentials: ErrorCode 1 of the cash register
 * service, -100 (wrong service user or password) of the waybill service.
 */
final class RsgeAuthenticationException extends RsgeException {}
