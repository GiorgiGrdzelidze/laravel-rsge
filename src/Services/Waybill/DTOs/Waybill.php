<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Support\WaybillXml;

/**
 * A waybill read from rs.ge, from get_waybill or from a row of a list.
 *
 * Amounts stay strings exactly as rs.ge wrote them. A list row has no goods and fewer tags.
 */
final class Waybill
{
    /**
     * @param  ?string  $number  Empty until the waybill is activated.
     * @param  ?\DateTimeImmutable  $activatedAt  Null for a waybill that has no number yet.
     * @param  ?bool  $confirmedByBuyer  True once the buyer confirmed; false while they have not, or rejected (raw IS_CONFIRMED tells which).
     * @param  ?int  $serviceUserId  The service user that created it (S_USER_ID).
     * @param  list<WaybillGoods>  $goods
     * @param  array<string, string>  $raw  Every header tag rs.ge returned.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $typeId,
        public readonly int $statusId,
        public readonly ?string $number,
        public readonly ?int $serviceUserId,
        public readonly ?int $sellerUnId,
        public readonly string $buyerTin,
        public readonly string $buyerName,
        public readonly string $fullAmount,
        public readonly string $comment,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $activatedAt,
        public readonly ?\DateTimeImmutable $beginsAt,
        public readonly ?\DateTimeImmutable $closedAt,
        public readonly ?bool $confirmedByBuyer,
        public readonly array $goods,
        public readonly array $raw,
    ) {}

    public static function fromXml(\SimpleXMLElement $waybill): self
    {
        $goods = [];
        foreach ($waybill->children() as $child) {
            if ($child->getName() === 'GOODS_LIST') {
                foreach (WaybillXml::rows($child, 'GOODS') as $row) {
                    $goods[] = WaybillGoods::fromRow($row);
                }
            }
        }

        $raw = WaybillXml::fields($waybill);
        $int = static fn (string $tag): ?int => ($raw[$tag] ?? '') !== '' ? (int) $raw[$tag] : null;
        $confirmed = $raw['IS_CONFIRMED'] ?? '';

        return new self(
            id: (int) ($raw['ID'] ?? 0),
            typeId: (int) ($raw['TYPE'] ?? 0),
            statusId: (int) ($raw['STATUS'] ?? 0),
            number: ($raw['WAYBILL_NUMBER'] ?? '') !== '' ? $raw['WAYBILL_NUMBER'] : null,
            serviceUserId: $int('S_USER_ID'),
            sellerUnId: $int('SELER_UN_ID'),
            buyerTin: $raw['BUYER_TIN'] ?? '',
            buyerName: $raw['BUYER_NAME'] ?? '',
            fullAmount: $raw['FULL_AMOUNT'] ?? '',
            // a list row calls the comment WAYBILL_COMMENT
            comment: $raw['COMMENT'] ?? $raw['WAYBILL_COMMENT'] ?? '',
            createdAt: WaybillXml::parseDate($raw['CREATE_DATE'] ?? null),
            // rs.ge fills ACTIVATE_DATE as soon as a waybill is saved; it means something once there is a number
            activatedAt: ($raw['WAYBILL_NUMBER'] ?? '') !== '' ? WaybillXml::parseDate($raw['ACTIVATE_DATE'] ?? null) : null,
            beginsAt: WaybillXml::parseDate($raw['BEGIN_DATE'] ?? null),
            closedAt: WaybillXml::parseDate($raw['CLOSE_DATE'] ?? null),
            confirmedByBuyer: match ($confirmed) {
                '1' => true,
                '0', '-1' => false,
                default => null,
            },
            goods: $goods,
            raw: $raw,
        );
    }

    public function type(): ?WaybillType
    {
        return WaybillType::tryFrom($this->typeId);
    }

    public function status(): ?WaybillStatus
    {
        return WaybillStatus::tryFrom($this->statusId);
    }

    public function isActive(): bool
    {
        return $this->statusId === WaybillStatus::Active->value;
    }
}
