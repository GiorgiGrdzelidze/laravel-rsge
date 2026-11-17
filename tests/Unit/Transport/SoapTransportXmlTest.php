<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Services\Waybill\Requests\GoodsLine;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;
use Rsge\Laravel\Services\Waybill\Requests\WaybillFilter;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Tests\Support\CapturingSoapClient;
use Rsge\Laravel\Transport\SoapTransport;

/**
 * What really goes on the wire, built by PHP's SoapClient from rs.ge's own WSDL (a copy
 * of 2026-11-15). Nothing is sent.
 */
final class SoapTransportXmlTest extends TestCase
{
    private CapturingSoapClient $client;

    private WaybillService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new CapturingSoapClient(__DIR__.'/../../Fixtures/waybill/WayBillService.wsdl', ['cache_wsdl' => WSDL_CACHE_NONE]);
        $this->service = new WaybillService(
            (new SoapTransport)->setClient($this->client),
            new ServiceUserCredentials('SHOP_ONE:12345678910', 'se<cr>et&'),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/waybill/'.$name);
    }

    #[Test]
    public function save_waybill_goes_out_as_the_request_rs_ge_accepted(): void
    {
        $this->client->answer = '<save_waybillResponse xmlns="http://tempuri.org/"><save_waybillResult>'.$this->fixture('save_waybill.result.xml').'</save_waybillResult></save_waybillResponse>';

        $saved = $this->service->save(new WaybillDraft(
            type: WaybillType::WithoutTransport,
            goods: [
                new GoodsLine(name: 'ფანქარი HB (test)', quantity: 2, price: 1.5, amount: 3, barCode: '02345678'),
                new GoodsLine(name: 'აკრილის საღებავი 75მლ (test)', quantity: 1, price: 9.95, amount: 9.95, barCode: 'MSCH7501'),
            ],
            buyerTin: '206322102',
            buyerName: 'სატესტო კოდი1',
            startAddress: 'თბილისი, ტესტის 1',
            endAddress: 'თბილისი, ტესტის 1',
            beginDate: new \DateTimeImmutable('2026-11-15 13:10:28', new \DateTimeZone('Asia/Tbilisi')),
            comment: 'package test',
        ), sellerUnId: 1149251);

        // the waybill fragment sits untouched inside the parameter, the password is escaped
        $this->assertSame(
            '<ns1:save_waybill><ns1:su>SHOP_ONE:12345678910</ns1:su><ns1:sp>se&lt;cr&gt;et&amp;</ns1:sp><ns1:waybill>'.$this->fixture('save_waybill.request.waybill.xml').'</ns1:waybill></ns1:save_waybill>',
            $this->client->body(),
        );
        // and the answer comes back through the real SOAP decoding
        $this->assertSame(1062469264, $saved->id);
        $this->assertSame([6309518989, 6309518990], $saved->goodsIds);
    }

    #[Test]
    public function a_waybill_is_read_back_through_the_real_soap_decoding(): void
    {
        $this->client->answer = '<get_waybillResponse xmlns="http://tempuri.org/"><get_waybillResult>'.$this->fixture('get_waybill.saved.xml').'</get_waybillResult></get_waybillResponse>';

        $waybill = $this->service->get(1062469264);

        $this->assertSame('<ns1:get_waybill><ns1:su>SHOP_ONE:12345678910</ns1:su><ns1:sp>se&lt;cr&gt;et&amp;</ns1:sp><ns1:waybill_id>1062469264</ns1:waybill_id></ns1:get_waybill>', $this->client->body());
        $this->assertSame([1062469264, 0, 2, 'ფანქარი HB (test)'], [$waybill->id, $waybill->statusId, count($waybill->goods), $waybill->goods[0]->name]);
    }

    #[Test]
    public function the_list_sends_the_filters_it_does_not_use_as_empty(): void
    {
        $this->client->answer = '<get_waybillsResponse xmlns="http://tempuri.org/"><get_waybillsResult>'.$this->fixture('get_waybills.xml').'</get_waybillsResult></get_waybillsResponse>';

        $waybills = $this->service->list(new WaybillFilter(new \DateTimeImmutable('2026-11-13 00:00:00', new \DateTimeZone('Asia/Tbilisi')), new \DateTimeImmutable('2026-11-15 23:59:59', new \DateTimeZone('Asia/Tbilisi')), serviceUserIds: [382402]));

        $this->assertSame(
            '<ns1:get_waybills><ns1:su>SHOP_ONE:12345678910</ns1:su><ns1:sp>se&lt;cr&gt;et&amp;</ns1:sp><ns1:itypes></ns1:itypes><ns1:buyer_tin></ns1:buyer_tin><ns1:statuses></ns1:statuses><ns1:car_number></ns1:car_number>'
            .'<ns1:begin_date_s xsi:nil="true"/><ns1:begin_date_e xsi:nil="true"/><ns1:create_date_s>2026-11-13T00:00:00</ns1:create_date_s><ns1:create_date_e>2026-11-15T23:59:59</ns1:create_date_e><ns1:driver_tin></ns1:driver_tin>'
            .'<ns1:delivery_date_s xsi:nil="true"/><ns1:delivery_date_e xsi:nil="true"/><ns1:full_amount xsi:nil="true"/><ns1:waybill_number></ns1:waybill_number><ns1:close_date_s xsi:nil="true"/><ns1:close_date_e xsi:nil="true"/>'
            .'<ns1:s_user_ids>,382402,</ns1:s_user_ids><ns1:comment></ns1:comment></ns1:get_waybills>',
            $this->client->body(),
        );
        $this->assertCount(2, $waybills);
    }

    #[Test]
    public function the_plain_operations_take_their_parameters_in_the_wsdls_order(): void
    {
        $bodies = [];
        foreach ([
            ['chek_service_user', '<chek_service_userResult>true</chek_service_userResult><un_id>1149251</un_id><s_user_id>382402</s_user_id>', fn () => $this->service->checkServiceUser()->serviceUserId],
            ['send_waybill', '<send_waybillResult>1021416743</send_waybillResult>', fn () => $this->service->activate(5)],
            ['send_waybil_vd', '<send_waybil_vdResult>1021416744</send_waybil_vdResult>', fn () => $this->service->activate(5, new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('Asia/Tbilisi')))],
            ['close_waybill', '<close_waybillResult>1</close_waybillResult>', fn () => $this->service->close(5)],
            ['close_waybill_vd', '<close_waybill_vdResult>1</close_waybill_vdResult>', fn () => $this->service->close(5, new \DateTimeImmutable('2026-11-16 18:00:00', new \DateTimeZone('Asia/Tbilisi')))],
            ['del_waybill', '<del_waybillResult>1</del_waybillResult>', fn () => $this->service->delete(5)],
            ['ref_waybill', '<ref_waybillResult>1</ref_waybillResult>', fn () => $this->service->cancel(5)],
            ['ref_waybill_vd', '<ref_waybill_vdResult>1</ref_waybill_vdResult>', fn () => $this->service->cancel(5, 'შეცდომა')],
            ['get_print_pdf', '<get_print_pdfResult>'.base64_encode("%PDF-1.4\nbytes").'</get_print_pdfResult>', fn () => $this->service->printPdf(5)],
            ['get_service_users', '<get_service_usersResult>'.$this->fixture('get_service_users.xml').'</get_service_usersResult>', fn () => $this->service->serviceUsers('main', 'pass')->count()],
        ] as [$operation, $answer, $run]) {
            $this->client->answer = "<{$operation}Response xmlns=\"http://tempuri.org/\">{$answer}</{$operation}Response>";
            $bodies[$operation] = [$run(), $this->client->body()];
        }

        $auth = '<ns1:su>SHOP_ONE:12345678910</ns1:su><ns1:sp>se&lt;cr&gt;et&amp;</ns1:sp>';
        $this->assertSame([
            'chek_service_user' => [382402, "<ns1:chek_service_user>{$auth}</ns1:chek_service_user>"],
            'send_waybill' => ['1021416743', "<ns1:send_waybill>{$auth}<ns1:waybill_id>5</ns1:waybill_id></ns1:send_waybill>"],
            'send_waybil_vd' => ['1021416744', "<ns1:send_waybil_vd>{$auth}<ns1:begin_date>2026-11-16T09:00:00</ns1:begin_date><ns1:waybill_id>5</ns1:waybill_id></ns1:send_waybil_vd>"],
            'close_waybill' => [null, "<ns1:close_waybill>{$auth}<ns1:waybill_id>5</ns1:waybill_id></ns1:close_waybill>"],
            'close_waybill_vd' => [null, "<ns1:close_waybill_vd>{$auth}<ns1:delivery_date>2026-11-16T18:00:00</ns1:delivery_date><ns1:waybill_id>5</ns1:waybill_id></ns1:close_waybill_vd>"],
            'del_waybill' => [null, "<ns1:del_waybill>{$auth}<ns1:waybill_id>5</ns1:waybill_id></ns1:del_waybill>"],
            'ref_waybill' => [null, "<ns1:ref_waybill>{$auth}<ns1:waybill_id>5</ns1:waybill_id></ns1:ref_waybill>"],
            'ref_waybill_vd' => [null, "<ns1:ref_waybill_vd>{$auth}<ns1:waybill_id>5</ns1:waybill_id><ns1:comment>შეცდომა</ns1:comment></ns1:ref_waybill_vd>"],
            'get_print_pdf' => ["%PDF-1.4\nbytes", "<ns1:get_print_pdf>{$auth}<ns1:waybill_id>5</ns1:waybill_id></ns1:get_print_pdf>"],
            'get_service_users' => [2, '<ns1:get_service_users><ns1:user_name>main</ns1:user_name><ns1:user_password>pass</ns1:user_password></ns1:get_service_users>'],
        ], $bodies);
    }

    #[Test]
    public function a_soap_fault_is_reported_without_the_request_it_was_thrown_for(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        $failing = new class(__DIR__.'/../../Fixtures/waybill/WayBillService.wsdl', ['cache_wsdl' => WSDL_CACHE_NONE]) extends \SoapClient
        {
            public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): ?string
            {
                throw new \SoapFault('HTTP', 'Could not connect to host');
            }
        };
        $service = new WaybillService((new SoapTransport)->setClient($failing), new ServiceUserCredentials('SHOP_ONE:12345678910', 'SERVICE-PASSWORD-9'));

        try {
            $service->serviceUsers('main-user', 'MAIN-PASSWORD-7');
            $this->fail('nothing thrown');
        } catch (RsgeTransportException $e) {
            $this->assertStringContainsString("SOAP call 'get_service_users' failed: Could not connect to host", $e->getMessage());
            $this->assertStringContainsString('[HTTP]', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the fault carries the whole request in its own trace');
            $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\') && ! str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\Tests\\')));
            $this->assertNotSame([], $frames);
            $dump = print_r($frames, true).$e->getMessage().$e->getTraceAsString();
            $this->assertStringNotContainsString('MAIN-PASSWORD-7', $dump);
            $this->assertStringNotContainsString('SERVICE-PASSWORD-9', $dump);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    #[Test]
    public function a_wsdl_that_cannot_be_loaded_is_reported_without_the_clients_options(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $transport = new SoapTransport(__DIR__.'/no-such-file.wsdl', ['proxy_password' => 'PROXY-PASSWORD-5', 'cache_wsdl' => WSDL_CACHE_NONE, 'exceptions' => true]);

        try {
            @$transport->call('chek_service_user', ['su' => 'a:1', 'sp' => 'SERVICE-PASSWORD-9']);
            $this->fail('nothing thrown');
        } catch (RsgeTransportException $e) {
            $this->assertStringContainsString('Failed to create SoapClient', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\') && ! str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\Tests\\')));
            $this->assertNotSame([], $frames);
            $dump = print_r($frames, true).$e->getMessage().$e->getTraceAsString();
            $this->assertStringNotContainsString('PROXY-PASSWORD-5', $dump);
            $this->assertStringNotContainsString('SERVICE-PASSWORD-9', $dump);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }
}
