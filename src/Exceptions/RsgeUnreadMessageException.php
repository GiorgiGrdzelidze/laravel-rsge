<?php

declare(strict_types=1);

namespace Rsge\Laravel\Exceptions;

/**
 * rs.ge refuses to issue waybills until a mandatory message on the portal has been read.
 *
 * There is no web-service operation to read or acknowledge the message: someone has to
 * open the portal. The operation can be repeated afterwards.
 */
final class RsgeUnreadMessageException extends RsgeWaybillException
{
    public const ERROR_CODE = -1061;
}
