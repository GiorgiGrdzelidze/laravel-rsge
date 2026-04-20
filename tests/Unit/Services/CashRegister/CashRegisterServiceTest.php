<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Services\CashRegister;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Services\CashRegister\DTOs\ZReportDetail;
use Rsge\Laravel\Transport\FakeTransport;

final class CashRegisterServiceTest extends TestCase
{
    private FakeTransport $transport;

    private CashRegisterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport;
        $this->service = new CashRegisterService(
            $this->transport,
            new BasicCredentials('testuser', 'testpass'),
        );
    }

    #[Test]
    public function multi_item_array_hydration(): void
    {
        $this->transport->push([
            'ResponseStatus' => true,
            'ZreportDetailsData' => [
                'ZreportDetails' => [
                    ['DeviceNumber' => 'D1', 'ReportDate' => '2025-01-01T00:00:00', 'Quantity' => 10, 'PaidAmount' => 100.0, 'PaidCash' => 60.0, 'PaidOther' => 40.0, 'ZNumber' => 1],
                    ['DeviceNumber' => 'D2', 'ReportDate' => '2025-01-02T00:00:00', 'Quantity' => 20, 'PaidAmount' => 200.0, 'PaidCash' => 120.0, 'PaidOther' => 80.0, 'ZNumber' => 2],
                ],
            ],
        ]);

        $result = $this->service->getZReportDetails('2025-01-01', '2025-01-31');

        $this->assertCount(2, $result);
        $this->assertInstanceOf(ZReportDetail::class, $result[0]);
        $this->assertSame('D1', $result[0]->deviceNumber);
        $this->assertSame('D2', $result[1]->deviceNumber);
    }

    #[Test]
    public function single_item_assoc_normalization(): void
    {
        $this->transport->push([
            'ResponseStatus' => true,
            'ZreportDetailsData' => [
                'ZreportDetails' => [
                    'DeviceNumber' => 'SINGLE',
                    'ReportDate' => '2025-06-01T00:00:00',
                    'Quantity' => 5,
                    'PaidAmount' => 50.0,
                    'PaidCash' => 50.0,
                    'PaidOther' => 0.0,
                    'ZNumber' => 99,
                ],
            ],
        ]);

        $result = $this->service->getZReportDetails('2025-06-01', '2025-06-01');

        $this->assertCount(1, $result);
        $this->assertSame('SINGLE', $result[0]->deviceNumber);
    }

    #[Test]
    public function empty_list_returns_empty_collection(): void
    {
        $this->transport->push([
            'ResponseStatus' => true,
            'ZreportDetailsData' => [
                'ZreportDetails' => null,
            ],
        ]);

        $result = $this->service->getZReportDetails('2025-01-01', '2025-01-31');

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function soap_payload_carries_correct_fields(): void
    {
        $this->transport->push(['ResponseStatus' => true]);

        $this->service->getZReportDetails('2025-03-01', '2025-03-31');

        $call = $this->transport->lastCall();

        $this->assertNotNull($call);
        $this->assertSame(CashRegisterService::METHOD_GET_Z_REPORT_DETAILS, $call['method']);
        $this->assertSame('testuser', $call['parameters']['UserName']);
        $this->assertSame('testpass', $call['parameters']['Password']);
        $this->assertSame('2025-03-01T00:00:00', $call['parameters']['StartDate']);
        $this->assertSame('2025-03-31T00:00:00', $call['parameters']['EndDate']);
    }

    #[Test]
    public function inverted_date_range_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getZReportDetails('2025-12-31', '2025-01-01');
    }

    #[Test]
    public function error_code_0_throws_system_exception(): void
    {
        $this->transport->push([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 0, 'ErrorDescription' => 'სისტემური შეცდომა'],
        ]);

        $this->expectException(RsgeSystemException::class);

        $this->service->getZReportDetails('2025-01-01', '2025-01-31');
    }

    #[Test]
    public function error_code_1_throws_authentication_exception(): void
    {
        $this->transport->push([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 1, 'ErrorDescription' => 'არასწორი მომხმარებელი'],
        ]);

        $this->expectException(RsgeAuthenticationException::class);

        $this->service->getZReportDetails('2025-01-01', '2025-01-31');
    }

    #[Test]
    public function error_code_2_throws_not_found_exception(): void
    {
        $this->transport->push([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 2, 'ErrorDescription' => 'ინფორმაცია არ მოიძებნა'],
        ]);

        $this->expectException(RsgeNotFoundException::class);

        $this->service->getZReportDetails('2025-01-01', '2025-01-31');
    }

    #[Test]
    public function unknown_error_code_throws_base_exception(): void
    {
        $this->transport->push([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 42, 'ErrorDescription' => 'unknown'],
        ]);

        try {
            $this->service->getZReportDetails('2025-01-01', '2025-01-31');
            $this->fail('Expected RsgeException');
        } catch (RsgeException $e) {
            $this->assertNotInstanceOf(RsgeSystemException::class, $e);
            $this->assertNotInstanceOf(RsgeAuthenticationException::class, $e);
            $this->assertNotInstanceOf(RsgeNotFoundException::class, $e);
            $this->assertSame(42, $e->errorCode);
        }
    }

    #[Test]
    public function stdclass_tree_response_hydrates_correctly(): void
    {
        $detail = new \stdClass;
        $detail->DeviceNumber = 'OBJ-1';
        $detail->ReportDate = '2025-04-01T10:00:00';
        $detail->Quantity = 3;
        $detail->PaidAmount = 300.0;
        $detail->PaidCash = 200.0;
        $detail->PaidOther = 100.0;
        $detail->ZNumber = 5;

        $data = new \stdClass;
        $data->ZreportDetails = [$detail];

        $response = new \stdClass;
        $response->ResponseStatus = true;
        $response->ZreportDetailsData = $data;

        $this->transport->push($response);

        $result = $this->service->getZReportDetails('2025-04-01', '2025-04-30');

        $this->assertCount(1, $result);
        $this->assertSame('OBJ-1', $result[0]->deviceNumber);
    }

    #[Test]
    public function string_date_inputs_parse_correctly(): void
    {
        $this->transport->push(['ResponseStatus' => true]);

        $this->service->getZReportDetails('2025-01-01', '2025-01-31');

        $params = $this->transport->lastCall()['parameters'];

        $this->assertSame('2025-01-01T00:00:00', $params['StartDate']);
        $this->assertSame('2025-01-31T00:00:00', $params['EndDate']);
    }

    #[Test]
    public function datetime_interface_inputs_are_accepted(): void
    {
        $this->transport->push(['ResponseStatus' => true]);

        $start = new \DateTimeImmutable('2025-06-01 08:00:00');
        $end = new \DateTimeImmutable('2025-06-30 23:59:59');

        $this->service->getZReportDetails($start, $end);

        $params = $this->transport->lastCall()['parameters'];

        $this->assertSame('2025-06-01T08:00:00', $params['StartDate']);
        $this->assertSame('2025-06-30T23:59:59', $params['EndDate']);
    }

    #[Test]
    public function single_stdclass_item_is_normalized(): void
    {
        $detail = new \stdClass;
        $detail->DeviceNumber = 'SOLO';
        $detail->ReportDate = '2025-05-01T00:00:00';
        $detail->Quantity = 1;
        $detail->PaidAmount = 10.0;
        $detail->PaidCash = 10.0;
        $detail->PaidOther = 0.0;
        $detail->ZNumber = 1;

        $data = new \stdClass;
        $data->ZreportDetails = $detail;

        $response = new \stdClass;
        $response->ResponseStatus = true;
        $response->ZreportDetailsData = $data;

        $this->transport->push($response);

        $result = $this->service->getZReportDetails('2025-05-01', '2025-05-31');

        $this->assertCount(1, $result);
        $this->assertSame('SOLO', $result[0]->deviceNumber);
    }

    #[Test]
    public function missing_zreport_details_data_returns_empty(): void
    {
        $this->transport->push(['ResponseStatus' => true]);

        $result = $this->service->getZReportDetails('2025-01-01', '2025-01-31');

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function service_name_returns_cash_register(): void
    {
        $this->assertSame('cash_register', $this->service->name());
    }
}
