<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Enums;

/**
 * Waybill statuses.
 *
 * Saved, Active and Closed come from the official manual; Deleted was observed on the
 * live service after del_waybill; Cancelled and SentToTransporter appear in rs.ge samples.
 */
enum WaybillStatus: int
{
    case Saved = 0;
    case Active = 1;
    case Closed = 2;
    case SentToTransporter = 8;
    case Deleted = -1;
    case Cancelled = -2;
}
