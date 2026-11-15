<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * An entry of an rs.ge list: a waybill type, a unit or a transport type.
 */
final class DictionaryItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
