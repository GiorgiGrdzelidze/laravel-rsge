<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Enums;

/**
 * Transport types, as returned by get_trans_types.
 */
enum TransportType: int
{
    case Road = 1;
    case Rail = 2;
    case Air = 3;
    case Other = 4;
    case ForeignRoad = 6;
    case Carrier = 7;
    case Moped = 8;
}
