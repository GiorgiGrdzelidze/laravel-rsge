<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\CashRegister\Requests;

/**
 * Validated request DTO for the Get_Z_Report_Details SOAP call.
 */
final class GetZReportRequest
{
    public readonly \DateTimeImmutable $startDate;

    public readonly \DateTimeImmutable $endDate;

    /**
     * @throws \InvalidArgumentException When startDate is after endDate.
     */
    public function __construct(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
    ) {
        $this->startDate = \DateTimeImmutable::createFromInterface($startDate);
        $this->endDate = \DateTimeImmutable::createFromInterface($endDate);

        if ($this->startDate > $this->endDate) {
            throw new \InvalidArgumentException(
                "GetZReportRequest: startDate ({$this->startDate->format('Y-m-d')}) must not be after endDate ({$this->endDate->format('Y-m-d')}).",
            );
        }
    }

    /**
     * Format for the rs.ge SOAP payload.
     *
     * @return array{StartDate: string, EndDate: string}
     */
    public function toSoap(): array
    {
        return [
            'StartDate' => $this->startDate->format('Y-m-d\TH:i:s'),
            'EndDate' => $this->endDate->format('Y-m-d\TH:i:s'),
        ];
    }
}
