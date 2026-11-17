<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Requests;

use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Support\WaybillXml;

/**
 * Filters of get_waybills. rs.ge demands a creation-date range of at most 72 hours
 * (error -1064 otherwise) and leaves deleted waybills out of the list.
 */
final class WaybillFilter
{
    /**
     * @param  list<WaybillType>  $types
     * @param  list<WaybillStatus>  $statuses
     * @param  list<int>  $serviceUserIds  Only waybills these service users created (S_USER_ID).
     */
    public function __construct(
        public readonly \DateTimeInterface $createdFrom,
        public readonly \DateTimeInterface $createdTo,
        public readonly array $types = [],
        public readonly array $statuses = [],
        public readonly array $serviceUserIds = [],
        public readonly string $buyerTin = '',
        public readonly string $waybillNumber = '',
        public readonly string $comment = '',
        public readonly string $carNumber = '',
        public readonly string $driverTin = '',
    ) {
        if ($createdTo < $createdFrom) {
            throw new \InvalidArgumentException('The creation-date range of a waybill list ends before it starts.');
        }
    }

    /**
     * Parameters left out are sent as empty (nil) by the SOAP client. A list of statuses or of
     * service users is read by rs.ge only inside commas (",0,8,"); written plainly it matches nothing.
     *
     * @return array<string, string>
     */
    public function toSoap(): array
    {
        return [
            'itypes' => implode(',', array_map(static fn (WaybillType $type): int => $type->value, $this->types)),
            'buyer_tin' => trim($this->buyerTin),
            'statuses' => self::between(array_map(static fn (WaybillStatus $status): int => $status->value, $this->statuses)),
            'car_number' => trim($this->carNumber),
            'create_date_s' => WaybillXml::date($this->createdFrom),
            'create_date_e' => WaybillXml::date($this->createdTo),
            'driver_tin' => trim($this->driverTin),
            'waybill_number' => trim($this->waybillNumber),
            's_user_ids' => self::between(array_map(static fn (int $id): int => $id, $this->serviceUserIds)),
            'comment' => $this->comment,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private static function between(array $values): string
    {
        return $values === [] ? '' : ','.implode(',', $values).',';
    }
}
