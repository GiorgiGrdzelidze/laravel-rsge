<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * Thrown when rs.ge has no data for the requested criteria (ErrorCode 2).
 */
final class RsgeNotFoundException extends RsgeException {}
