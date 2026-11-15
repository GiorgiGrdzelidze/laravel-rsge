<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Requests;

use Rsge\Laravel\Services\Waybill\Enums\VatType;
use Rsge\Laravel\Support\WaybillXml;

/**
 * One goods line of a waybill that is being saved.
 */
final class GoodsLine
{
    public const UNIT_PIECE = 1;

    public const UNIT_OTHER = 99;

    /**
     * @param  int  $id  0 for a new line; the line id rs.ge returned to change an existing one.
     * @param  ?string  $unitText  Required by rs.ge when the unit is "other" (99).
     * @param  int  $exciseId  0 when the goods are not excisable.
     * @param  bool  $remove  Take an existing line off the waybill.
     */
    public function __construct(
        public readonly string $name,
        public readonly int|float|string $quantity,
        public readonly int|float|string $price,
        public readonly int|float|string $amount,
        public readonly string $barCode = '',
        public readonly int $unitId = self::UNIT_PIECE,
        public readonly ?string $unitText = null,
        public readonly VatType $vatType = VatType::Standard,
        public readonly int $exciseId = 0,
        public readonly int $id = 0,
        public readonly bool $remove = false,
    ) {
        // what is left of the name once broken bytes and control characters are gone
        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        try {
            $readable = (string) preg_replace('/[\p{C}\s]+/u', '', mb_convert_encoding($name, 'UTF-8', 'UTF-8'));
        } finally {
            mb_substitute_character($substitute);
        }
        if ($readable === '') {
            throw new \InvalidArgumentException('A goods line needs a name.');
        }
        // a number rs.ge could not read is refused here, not after a call to rs.ge
        foreach ([$quantity, $price, $amount] as $number) {
            WaybillXml::number($number);
        }
        if ($unitId === self::UNIT_OTHER && trim((string) $unitText) === '') {
            throw new \InvalidArgumentException('A goods line with the unit "other" (99) needs the unit text.');
        }
    }

    public function toXml(): string
    {
        return WaybillXml::element('GOODS', [
            'ID' => $this->id,
            'W_NAME' => trim($this->name),
            'UNIT_ID' => $this->unitId,
            'UNIT_TXT' => $this->unitText,
            'QUANTITY' => WaybillXml::number($this->quantity),
            'PRICE' => WaybillXml::number($this->price),
            'STATUS' => $this->remove ? -1 : 1,
            'AMOUNT' => WaybillXml::number($this->amount),
            'BAR_CODE' => $this->barCode,
            'A_ID' => $this->exciseId,
            'VAT_TYPE' => $this->vatType->value,
        ]);
    }
}
