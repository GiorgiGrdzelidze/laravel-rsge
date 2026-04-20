<?php

declare(strict_types=1);

/**
 * Example: Fetch Z-report details from rs.ge
 *
 * This script demonstrates how to use the laravel-rsge package
 * to retrieve cash register Z-report data from the Georgian
 * Revenue Service (rs.ge) API.
 *
 * Prerequisites:
 * - Configure RSGE_USERNAME and RSGE_PASSWORD in your .env file.
 * - Run: php artisan vendor:publish --tag=rsge-config
 */

use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\Support\Retry;

// Step 1: Define the date range for the report.
$startDate = '2025-01-01';
$endDate = '2025-01-31';

// Step 2: Fetch Z-report details with automatic retry on transient errors.
try {
    $reports = Retry::onSystemError(
        operation: fn () => Rsge::cashRegister()->getZReportDetails($startDate, $endDate),
        attempts: 3,
        baseDelayMs: 500,
    );
} catch (RsgeAuthenticationException $e) {
    // Invalid username or password — check .env credentials.
    throw $e;
} catch (RsgeNotFoundException) {
    // No data available for the given date range.
    $reports = collect();
} catch (RsgeSystemException $e) {
    // Transient system error persisted after all retry attempts.
    throw $e;
} catch (RsgeTransportException $e) {
    // Network or SOAP protocol failure.
    throw $e;
}

// Step 3: Process the results.
foreach ($reports as $report) {
    // Each $report is an Rsge\Laravel\Services\CashRegister\DTOs\ZReportDetail instance.
    $line = sprintf(
        'Device: %s | Date: %s | Receipts: %d | Total: %.2f | Cash: %.2f | Other: %.2f | Z#: %d',
        $report->deviceNumber,
        $report->reportDate->format('Y-m-d'),
        $report->quantity,
        $report->paidAmount,
        $report->paidCash,
        $report->paidOther,
        $report->zNumber,
    );

    // In a real application, you would store these in the database,
    // display in a Filament table, or export to a report.
    logger()->info($line);
}
