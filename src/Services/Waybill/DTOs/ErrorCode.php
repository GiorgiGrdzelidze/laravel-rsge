<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * An error code of the waybill service, as get_error_codes lists it.
 */
final class ErrorCode
{
    /**
     * @param  ?int  $type  1 = waybill, 2 = goods line, 3 = invoice; rs.ge uses other values too.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $text,
        public readonly ?int $type,
    ) {}
}
