<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\CashRegister\DTOs;

/**
 * Immutable DTO representing a single Z-report detail from rs.ge.
 */
final class ZReportDetail
{
    public function __construct(
        public readonly string $deviceNumber,
        public readonly \DateTimeImmutable $reportDate,
        public readonly int $quantity,
        public readonly float $paidAmount,
        public readonly float $paidCash,
        public readonly float $paidOther,
        public readonly int $zNumber,
    ) {}

    /**
     * Hydrate from a raw SOAP response item (stdClass or array).
     *
     * @param  array<string, mixed>|object  $raw
     *
     * @throws \InvalidArgumentException When ReportDate is missing or unparseable.
     */
    public static function fromSoap(array|object $raw): self
    {
        $get = static function (string $key) use ($raw): mixed {
            if (is_array($raw)) {
                return $raw[$key] ?? null;
            }

            return $raw->{$key} ?? null;
        };

        $rawDate = $get('ReportDate');

        if ($rawDate === null) {
            throw new \InvalidArgumentException('ZReportDetail: missing ReportDate field.');
        }

        if ($rawDate instanceof \DateTimeInterface) {
            $reportDate = \DateTimeImmutable::createFromInterface($rawDate);
        } elseif (is_string($rawDate)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $rawDate)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $rawDate)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);

            if ($parsed === false) {
                throw new \InvalidArgumentException("ZReportDetail: unparseable ReportDate '{$rawDate}'.");
            }

            $reportDate = $parsed;
        } else {
            throw new \InvalidArgumentException('ZReportDetail: ReportDate must be a DateTimeInterface or string.');
        }

        return new self(
            deviceNumber: self::toString($get('DeviceNumber'), ''),
            reportDate: $reportDate,
            quantity: self::toInt($get('Quantity')),
            paidAmount: self::toFloat($get('PaidAmount')),
            paidCash: self::toFloat($get('PaidCash')),
            paidOther: self::toFloat($get('PaidOther')),
            zNumber: self::toInt($get('ZNumber')),
        );
    }

    /**
     * Convert to a plain array representation.
     *
     * @return array{deviceNumber: string, reportDate: string, quantity: int, paidAmount: float, paidCash: float, paidOther: float, zNumber: int}
     */
    public function toArray(): array
    {
        return [
            'deviceNumber' => $this->deviceNumber,
            'reportDate' => $this->reportDate->format(\DateTimeInterface::ATOM),
            'quantity' => $this->quantity,
            'paidAmount' => $this->paidAmount,
            'paidCash' => $this->paidCash,
            'paidOther' => $this->paidOther,
            'zNumber' => $this->zNumber,
        ];
    }

    private static function toString(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
