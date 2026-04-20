<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Services\CashRegister;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Services\CashRegister\DTOs\ZReportDetail;

final class ZReportDetailTest extends TestCase
{
    #[Test]
    public function from_soap_hydrates_from_array(): void
    {
        $detail = ZReportDetail::fromSoap([
            'DeviceNumber' => 'DEV-001',
            'ReportDate' => '2025-03-15T00:00:00',
            'Quantity' => 42,
            'PaidAmount' => 1500.50,
            'PaidCash' => 900.00,
            'PaidOther' => 600.50,
            'ZNumber' => 7,
        ]);

        $this->assertSame('DEV-001', $detail->deviceNumber);
        $this->assertSame('2025-03-15', $detail->reportDate->format('Y-m-d'));
        $this->assertSame(42, $detail->quantity);
        $this->assertSame(1500.50, $detail->paidAmount);
        $this->assertSame(900.00, $detail->paidCash);
        $this->assertSame(600.50, $detail->paidOther);
        $this->assertSame(7, $detail->zNumber);
    }

    #[Test]
    public function from_soap_hydrates_from_stdclass(): void
    {
        $raw = new \stdClass;
        $raw->DeviceNumber = 'DEV-002';
        $raw->ReportDate = '2025-06-01T12:30:00';
        $raw->Quantity = 10;
        $raw->PaidAmount = 500.0;
        $raw->PaidCash = 300.0;
        $raw->PaidOther = 200.0;
        $raw->ZNumber = 3;

        $detail = ZReportDetail::fromSoap($raw);

        $this->assertSame('DEV-002', $detail->deviceNumber);
        $this->assertSame(3, $detail->zNumber);
    }

    #[Test]
    public function from_soap_accepts_datetime_interface_report_date(): void
    {
        $raw = [
            'DeviceNumber' => 'DEV-003',
            'ReportDate' => new \DateTimeImmutable('2025-01-01'),
            'Quantity' => 1,
            'PaidAmount' => 10.0,
            'PaidCash' => 10.0,
            'PaidOther' => 0.0,
            'ZNumber' => 1,
        ];

        $detail = ZReportDetail::fromSoap($raw);

        $this->assertSame('2025-01-01', $detail->reportDate->format('Y-m-d'));
    }

    #[Test]
    public function from_soap_throws_on_missing_report_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing ReportDate');

        ZReportDetail::fromSoap(['DeviceNumber' => 'DEV-X']);
    }

    #[Test]
    public function to_array_returns_camel_case_keys(): void
    {
        $detail = new ZReportDetail(
            deviceNumber: 'DEV-001',
            reportDate: new \DateTimeImmutable('2025-03-15T00:00:00+00:00'),
            quantity: 5,
            paidAmount: 100.0,
            paidCash: 60.0,
            paidOther: 40.0,
            zNumber: 2,
        );

        $arr = $detail->toArray();

        $this->assertArrayHasKey('deviceNumber', $arr);
        $this->assertArrayHasKey('reportDate', $arr);
        $this->assertArrayHasKey('zNumber', $arr);
        $this->assertSame(5, $arr['quantity']);
    }
}
