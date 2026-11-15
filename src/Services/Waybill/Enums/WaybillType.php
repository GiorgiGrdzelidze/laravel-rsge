<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Enums;

/**
 * Waybill types, as returned by get_waybill_types.
 */
enum WaybillType: int
{
    case Internal = 1;
    case WithTransport = 2;
    case WithoutTransport = 3;
    case Distribution = 4;
    case Return = 5;
    case SubWaybill = 6;
}
