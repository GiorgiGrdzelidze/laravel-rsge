<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Enums;

/**
 * VAT treatment of a goods line; used when an invoice is made from the waybill.
 */
enum VatType: int
{
    case Standard = 0;
    case Zero = 1;
    case Exempt = 2;
}
