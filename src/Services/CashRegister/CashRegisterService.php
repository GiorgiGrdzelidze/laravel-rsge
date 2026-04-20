<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\CashRegister;

use Illuminate\Support\Collection;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Services\AbstractService;
use Rsge\Laravel\Services\CashRegister\DTOs\ZReportDetail;
use Rsge\Laravel\Services\CashRegister\Requests\GetZReportRequest;

/**
 * Service for interacting with the rs.ge Cash Register (Z-Report) API.
 */
final class CashRegisterService extends AbstractService
{
    public const METHOD_GET_Z_REPORT_DETAILS = 'Get_Z_Report_Details';

    public function name(): string
    {
        return 'cash_register';
    }

    /**
     * Fetch Z-report details between two dates (inclusive).
     *
     * @return Collection<int, ZReportDetail>
     *
     * @throws RsgeAuthenticationException On invalid credentials.
     * @throws RsgeNotFoundException When rs.ge has no data for the range.
     * @throws RsgeSystemException On transient rs.ge system errors.
     * @throws RsgeTransportException On network / SOAP failures.
     * @throws RsgeException On any other rs.ge error.
     */
    public function getZReportDetails(
        \DateTimeInterface|string $startDate,
        \DateTimeInterface|string $endDate,
    ): Collection {
        $start = $startDate instanceof \DateTimeInterface
            ? $startDate
            : new \DateTimeImmutable($startDate);

        $end = $endDate instanceof \DateTimeInterface
            ? $endDate
            : new \DateTimeImmutable($endDate);

        $request = new GetZReportRequest($start, $end);

        $response = $this->invoke(
            self::METHOD_GET_Z_REPORT_DETAILS,
            $this->withAuth($request->toSoap()),
        );

        return $this->hydrateDetails($response);
    }

    /**
     * Extract and normalize ZreportDetails from the response envelope.
     *
     * @param  array<string, mixed>|object  $response
     * @return Collection<int, ZReportDetail>
     */
    private function hydrateDetails(array|object $response): Collection
    {
        $data = $this->extract($response, 'ZreportDetailsData');

        if ($data === null) {
            return new Collection;
        }

        $details = $this->extract($data, 'ZreportDetails');

        if ($details === null) {
            return new Collection;
        }

        // Normalize: single object → array of one
        if (is_object($details) && ! is_iterable($details)) {
            $details = [$details];
        }

        if (is_array($details) && $details !== [] && ! array_is_list($details)) {
            // Associative array = single item
            $details = [$details];
        }

        /** @var iterable<mixed> $details */
        $items = [];
        foreach ($details as $item) {
            /** @var array<string, mixed>|object $item */
            $items[] = ZReportDetail::fromSoap($item);
        }

        return (new Collection($items))->values();
    }

    private function extract(mixed $source, string $key): mixed
    {
        if (is_array($source) && array_key_exists($key, $source)) {
            return $source[$key];
        }

        if (is_object($source) && property_exists($source, $key)) {
            return $source->{$key};
        }

        return null;
    }
}
