<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * A goods line of a waybill read from rs.ge.
 */
final class WaybillGoods
{
    /**
     * @param  array<string, string>  $raw  Every tag rs.ge returned for the line.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $unitId,
        public readonly string $quantity,
        public readonly string $price,
        public readonly string $amount,
        public readonly string $barCode,
        public readonly ?int $vatType,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, string>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['ID'] ?? 0),
            name: $row['W_NAME'] ?? '',
            unitId: ($row['UNIT_ID'] ?? '') !== '' ? (int) $row['UNIT_ID'] : null,
            quantity: $row['QUANTITY'] ?? '',
            price: $row['PRICE'] ?? '',
            amount: $row['AMOUNT'] ?? '',
            barCode: $row['BAR_CODE'] ?? '',
            vatType: ($row['VAT_TYPE'] ?? '') !== '' ? (int) $row['VAT_TYPE'] : null,
            raw: $row,
        );
    }
}
