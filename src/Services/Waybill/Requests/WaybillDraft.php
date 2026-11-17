<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\Requests;

use Rsge\Laravel\Services\Waybill\Enums\TransportType;
use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Support\WaybillXml;
use Rsge\Laravel\Support\XmlNode;

/**
 * A waybill to save with save_waybill: a new one (id 0) or a change to a saved one.
 *
 * rs.ge checks the rest itself and answers with its error codes; only what would make
 * the request meaningless is refused here.
 *
 * A waybill for a carrier company (handedToCarrier) is stored as "sent to the carrier": the
 * carrier puts its driver and car on it and starts the transport, and only then does rs.ge
 * give it a number. It is never activated by the seller. Without handedToCarrier the carrier
 * is only named: the waybill is saved like any other, and activating it starts it with no driver.
 */
final class WaybillDraft
{
    public const COST_PAID_BY_BUYER = 1;

    public const COST_PAID_BY_SELLER = 2;

    /**
     * @param  list<GoodsLine>  $goods
     * @param  bool  $buyerIsResident  CHEK_BUYER_TIN: true for a Georgian citizen or company, false for a foreigner.
     * @param  ?string  $transportText  Required by rs.ge when the transport type is "other".
     * @param  int  $id  0 for a new waybill.
     * @param  bool  $handedToCarrier  Store it as sent to the carrier named by $transporterTin (status 8).
     */
    public function __construct(
        public readonly WaybillType $type,
        public readonly array $goods,
        public readonly string $buyerTin = '',
        public readonly string $buyerName = '',
        public readonly bool $buyerIsResident = true,
        public readonly string $startAddress = '',
        public readonly string $endAddress = '',
        public readonly ?TransportType $transportType = null,
        public readonly ?string $transportText = null,
        public readonly string $driverTin = '',
        public readonly string $driverName = '',
        public readonly bool $driverIsResident = true,
        public readonly string $carNumber = '',
        public readonly int|float|string $transportCost = 0,
        public readonly int $transportCostPayer = self::COST_PAID_BY_BUYER,
        public readonly ?\DateTimeInterface $beginDate = null,
        public readonly string $comment = '',
        public readonly string $receptionInfo = '',
        public readonly string $receiverInfo = '',
        public readonly string $transporterTin = '',
        public readonly ?int $parentId = null,
        public readonly int $id = 0,
        public readonly bool $handedToCarrier = false,
    ) {
        foreach ($goods as $line) {
            if (! $line instanceof GoodsLine) {
                throw new \InvalidArgumentException('Every goods entry of a waybill must be a GoodsLine.');
            }
        }
        if (! in_array($transportCostPayer, [self::COST_PAID_BY_BUYER, self::COST_PAID_BY_SELLER], true)) {
            throw new \InvalidArgumentException('The transport cost is paid by the buyer (1) or the seller (2).');
        }
        WaybillXml::number($transportCost);
        if ($handedToCarrier) {
            $this->assertFitForACarrier();
        }
    }

    /**
     * What rs.ge refuses for a waybill sent to a carrier. Seen on the live service: no transport
     * type (-1002), no carrier (-1050). From rs.ge's list of codes: another waybill type (-1062),
     * a driver or a car of the seller's own (-1053).
     */
    private function assertFitForACarrier(): void
    {
        if (! in_array($this->type, [WaybillType::WithTransport, WaybillType::Internal], true)) {
            throw new \InvalidArgumentException('Only a waybill with transport or an internal one can be handed to a carrier.');
        }
        if ($this->transportType !== TransportType::Carrier) {
            throw new \InvalidArgumentException('A waybill handed to a carrier has the transport type "carrier".');
        }
        if (trim($this->transporterTin) === '') {
            throw new \InvalidArgumentException('A waybill handed to a carrier needs the carrier\'s tax id.');
        }
        if (trim($this->driverTin) !== '' || trim($this->driverName) !== '' || trim($this->carNumber) !== '') {
            throw new \InvalidArgumentException('The driver and the car of a waybill handed to a carrier are filled in by the carrier.');
        }
    }

    /**
     * The amounts of the lines that stay on the waybill, added up.
     */
    public function fullAmount(): string
    {
        $total = 0.0;
        foreach ($this->goods as $line) {
            if (! $line->remove) {
                $total += (float) WaybillXml::number($line->amount);
            }
        }

        return WaybillXml::number(round($total, 4));
    }

    /**
     * The WAYBILL fragment for save_waybill.
     *
     * @param  int  $sellerUnId  The seller's unique number (un_id), as chek_service_user returns it.
     */
    public function toXml(int $sellerUnId): string
    {
        $goods = '';
        foreach ($this->goods as $line) {
            $goods .= $line->toXml();
        }

        return WaybillXml::element('WAYBILL', [
            'SUB_WAYBILLS' => null,
            'GOODS_LIST' => new XmlNode($goods),
            'ID' => $this->id,
            'TYPE' => $this->type->value,
            'BUYER_TIN' => trim($this->buyerTin),
            'CHEK_BUYER_TIN' => $this->buyerIsResident ? 1 : 0,
            'BUYER_NAME' => trim($this->buyerName),
            'START_ADDRESS' => trim($this->startAddress),
            'END_ADDRESS' => trim($this->endAddress),
            'DRIVER_TIN' => trim($this->driverTin),
            'CHEK_DRIVER_TIN' => trim($this->driverTin) === '' ? null : ($this->driverIsResident ? 1 : 0),
            'DRIVER_NAME' => trim($this->driverName),
            'TRANSPORT_COAST' => WaybillXml::number($this->transportCost),
            'RECEPTION_INFO' => $this->receptionInfo,
            'RECEIVER_INFO' => $this->receiverInfo,
            'DELIVERY_DATE' => null,
            'STATUS' => $this->handedToCarrier ? WaybillStatus::SentToTransporter->value : WaybillStatus::Saved->value,
            'SELER_UN_ID' => $sellerUnId,
            'PAR_ID' => $this->parentId,
            'FULL_AMOUNT' => $this->fullAmount(),
            'CAR_NUMBER' => trim($this->carNumber),
            'WAYBILL_NUMBER' => null,
            'S_USER_ID' => null,
            // a waybill with no start date of its own starts now
            'BEGIN_DATE' => WaybillXml::date($this->beginDate ?? new \DateTimeImmutable),
            'TRAN_COST_PAYER' => $this->transportCostPayer,
            'TRANS_ID' => $this->transportType?->value,
            'TRANS_TXT' => $this->transportText,
            'COMMENT' => $this->comment,
        ] + (trim($this->transporterTin) !== '' ? ['TRANSPORTER_TIN' => trim($this->transporterTin)] : []), root: true);
    }
}
