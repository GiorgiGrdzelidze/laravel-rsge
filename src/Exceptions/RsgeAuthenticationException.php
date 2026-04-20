<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * Thrown when rs.ge rejects the supplied credentials (ErrorCode 1).
 */
final class RsgeAuthenticationException extends RsgeException {}
