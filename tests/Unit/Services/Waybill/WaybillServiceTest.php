<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Services\Waybill;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeConfigurationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Exceptions\RsgeUnreadMessageException;
use Rsge\Laravel\Exceptions\RsgeWaybillException;
use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Services\Waybill\Requests\GoodsLine;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;
use Rsge\Laravel\Services\Waybill\Requests\WaybillFilter;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Support\WaybillErrors;
use Rsge\Laravel\Support\XmlNode;
use Rsge\Laravel\Transport\FakeTransport;

final class WaybillServiceTest extends TestCase
{
    private const AUTH = ['su' => 'SHOP_ONE:12345678910', 'sp' => 'secret'];

    private FakeTransport $transport;

    private WaybillService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport;
        $this->service = new WaybillService($this->transport, new ServiceUserCredentials('SHOP_ONE:12345678910', 'secret'));
    }

    /**
     * An answer of rs.ge as the SOAP client hands it over: {operationResult: {any: "<xml>"}}.
     *
     * @return array<string, mixed>
     */
    private function xmlAnswer(string $operation, string $xml): array
    {
        return [$operation.'Result' => (object) ['any' => $xml]];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/waybill/'.$name);
    }

    private function draft(): WaybillDraft
    {
        return new WaybillDraft(
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
        );
    }

    #[Test]
    public function check_service_user_reports_who_it_is(): void
    {
        $this->transport->push((object) ['chek_service_userResult' => true, 'un_id' => 1149251, 's_user_id' => 382402]);

        $check = $this->service->checkServiceUser();

        $this->assertSame([true, 1149251, 382402], [$check->valid, $check->unId, $check->serviceUserId]);
        $this->assertSame(['method' => 'chek_service_user', 'parameters' => self::AUTH], $this->transport->lastCall());
    }

    #[Test]
    public function a_wrong_service_user_is_an_answer_and_carries_no_ids(): void
    {
        // what rs.ge answered to a wrong password on 2026-11-15
        $this->transport->push((object) ['chek_service_userResult' => false, 'un_id' => -3, 's_user_id' => -3]);

        $check = $this->service->checkServiceUser();

        $this->assertSame([false, null, null], [$check->valid, $check->unId, $check->serviceUserId]);
    }

    #[Test]
    public function service_users_are_listed_with_the_main_user(): void
    {
        $this->transport->push($this->xmlAnswer('get_service_users', $this->fixture('get_service_users.xml')));

        $users = $this->service->serviceUsers('mainuser', 'mainpass');

        $this->assertSame(['method' => 'get_service_users', 'parameters' => ['user_name' => 'mainuser', 'user_password' => 'mainpass']], $this->transport->lastCall());
        $this->assertSame([
            ['id' => 700001, 'user_name' => 'SHOP_ONE:12345678910', 'un_id' => 1149251, 'ip' => '203.0.113.7', 'name' => 'პირველი მაღაზია'],
            ['id' => 700002, 'user_name' => 'SHOP TWO:12345678910', 'un_id' => 1149251, 'ip' => null, 'name' => null],
        ], $users->map->toArray()->all());
    }

    #[Test]
    public function the_lists_rs_ge_keeps_are_read(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill_types', $this->fixture('get_waybill_types.xml')));
        $this->transport->push($this->xmlAnswer('get_waybill_units', $this->fixture('get_waybill_units.xml')));
        $this->transport->push($this->xmlAnswer('get_trans_types', $this->fixture('get_trans_types.xml')));
        $this->transport->push($this->xmlAnswer('get_error_codes', $this->fixture('get_error_codes.xml')));

        $types = $this->service->types();
        $units = $this->service->units();
        $transport = $this->service->transportTypes();
        $errors = $this->service->errorCodes();

        $this->assertSame([1, 2, 3, 4, 5, 6], $types->pluck('id')->all());
        $this->assertSame('ტრანსპორტირების გარეშე', $types->firstWhere('id', 3)?->name);
        $this->assertSame([1 => 'ცალი', 99 => 'სხვა'], $units->whereIn('id', [1, 99])->pluck('name', 'id')->all());
        $this->assertCount(14, $units);
        $this->assertSame([1, 2, 3, 4, 6, 7, 8], $transport->pluck('id')->all());
        $this->assertSame([-1, -100, -1036, -1061, -2005], $errors->pluck('id')->sortDesc()->values()->all());
        $unread = $errors->firstWhere('id', -1061);
        $this->assertNotNull($unread);
        $this->assertStringContainsString('წაუკითხავი', $unread->text);
        $this->assertSame(1, $unread->type);
        $this->assertSame(['get_waybill_types', 'get_waybill_units', 'get_trans_types', 'get_error_codes'], array_column($this->transport->calls(), 'method'));
        $this->assertSame(self::AUTH, $this->transport->calls()[0]['parameters']);
    }

    #[Test]
    public function a_list_refused_for_a_wrong_service_user_is_an_authentication_failure(): void
    {
        // what rs.ge answered on 2026-11-15
        $this->transport->push($this->xmlAnswer('get_error_codes', '<RESULT xmlns=""><STATUS>-100</STATUS></RESULT>'));

        try {
            $this->service->errorCodes();
            $this->fail('nothing thrown');
        } catch (RsgeAuthenticationException $e) {
            $this->assertSame(-100, $e->errorCode);
            $this->assertSame('სერვისის მომხმარებელი ან პაროლი არასწორია', $e->errorDescription);
        }
    }

    #[Test]
    public function a_waybill_is_saved_with_the_sellers_un_id_looked_up_once(): void
    {
        $this->transport->push((object) ['chek_service_userResult' => true, 'un_id' => 1149251, 's_user_id' => 382402]);
        $this->transport->push($this->xmlAnswer('save_waybill', $this->fixture('save_waybill.result.xml')));
        $this->transport->push($this->xmlAnswer('save_waybill', $this->fixture('save_waybill.result.xml')));

        $saved = $this->service->save($this->draft());
        $this->service->save($this->draft());

        $this->assertSame(1062469264, $saved->id);
        $this->assertSame([6309518989, 6309518990], $saved->goodsIds);

        $calls = $this->transport->calls();
        $this->assertSame(['chek_service_user', 'save_waybill', 'save_waybill'], array_column($calls, 'method'));
        $this->assertSame(['su', 'sp', 'waybill'], array_keys($calls[1]['parameters']));
        $waybill = $calls[1]['parameters']['waybill'];
        $this->assertInstanceOf(XmlNode::class, $waybill);
        // byte for byte the request rs.ge accepted on 2026-11-15
        $this->assertSame($this->fixture('save_waybill.request.waybill.xml'), $waybill->xml);
    }

    #[Test]
    public function a_given_un_id_saves_without_asking_rs_ge_for_it(): void
    {
        $this->transport->push($this->xmlAnswer('save_waybill', $this->fixture('save_waybill.result.xml')));

        $this->service->save($this->draft(), sellerUnId: 77);

        $this->assertSame(['save_waybill'], array_column($this->transport->calls(), 'method'));
        $waybill = $this->transport->calls()[0]['parameters']['waybill'];
        $this->assertInstanceOf(XmlNode::class, $waybill);
        $this->assertStringContainsString('<SELER_UN_ID>77</SELER_UN_ID>', $waybill->xml);
    }

    #[Test]
    public function saving_as_a_wrong_service_user_fails_before_anything_is_sent(): void
    {
        $this->transport->push((object) ['chek_service_userResult' => false, 'un_id' => -3, 's_user_id' => -3]);

        try {
            $this->service->save($this->draft());
            $this->fail('nothing thrown');
        } catch (RsgeAuthenticationException $e) {
            $this->assertSame(-100, $e->errorCode);
        }
        $this->assertSame(['chek_service_user'], array_column($this->transport->calls(), 'method'));
    }

    #[Test]
    public function a_refused_waybill_names_the_code_the_text_and_the_lines_at_fault(): void
    {
        $this->transport->push($this->xmlAnswer('save_waybill', '<RESULT xmlns=""><STATUS>-1036</STATUS><ID>0</ID><GOODS_LIST><GOODS><ERROR>0</ERROR><ID>0</ID><W_NAME>ა</W_NAME></GOODS><GOODS><ERROR>-2005</ERROR><ID>0</ID><W_NAME>ბ</W_NAME></GOODS></GOODS_LIST></RESULT>'));

        try {
            $this->service->save($this->draft(), sellerUnId: 1149251);
            $this->fail('nothing thrown');
        } catch (RsgeWaybillException $e) {
            $this->assertNotInstanceOf(RsgeUnreadMessageException::class, $e);
            $this->assertSame(-1036, $e->errorCode);
            $this->assertStringContainsString('ტრანსპორტირების გარეშე', (string) $e->errorDescription);
            $this->assertStringContainsString('-1036', $e->getMessage());
            $this->assertCount(1, $e->lineErrors);
            $this->assertSame([1, -2005], [$e->lineErrors[0]['line'], $e->lineErrors[0]['code']]);
            $this->assertSame(WaybillErrors::text(-2005), $e->lineErrors[0]['text']);
            $this->assertNotSame($e->errorDescription, $e->lineErrors[0]['text'], 'the line carries its own text, not the waybill\'s');
        }
    }

    #[Test]
    public function an_unread_mandatory_message_has_its_own_exception(): void
    {
        $this->transport->push($this->xmlAnswer('save_waybill', '<RESULT xmlns=""><STATUS>-1061</STATUS><ID>0</ID><GOODS_LIST></GOODS_LIST></RESULT>'));

        $this->expectException(RsgeUnreadMessageException::class);
        $this->expectExceptionMessage('წაუკითხავი');

        $this->service->save($this->draft(), sellerUnId: 1149251);
    }

    #[Test]
    public function an_accepted_waybill_without_an_id_is_not_taken_for_saved(): void
    {
        $this->transport->push($this->xmlAnswer('save_waybill', '<RESULT xmlns=""><STATUS>0</STATUS><ID>0</ID><GOODS_LIST></GOODS_LIST></RESULT>'));

        $this->expectException(RsgeException::class);

        $this->service->save($this->draft(), sellerUnId: 1149251);
    }

    #[Test]
    public function a_waybill_is_read_with_its_goods(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill', $this->fixture('get_waybill.saved.xml')));

        $waybill = $this->service->get(1062469264);

        $this->assertSame(['method' => 'get_waybill', 'parameters' => self::AUTH + ['waybill_id' => 1062469264]], $this->transport->lastCall());
        $this->assertSame(1062469264, $waybill->id);
        $this->assertSame(WaybillType::WithoutTransport, $waybill->type());
        $this->assertSame(WaybillStatus::Saved, $waybill->status());
        $this->assertFalse($waybill->isActive());
        $this->assertNull($waybill->number, 'a saved waybill has no number yet');
        $this->assertSame([382402, 1149251], [$waybill->serviceUserId, $waybill->sellerUnId]);
        $this->assertSame(['206322102', 'სატესტო კოდი1', '12.95', 'package test'], [$waybill->buyerTin, $waybill->buyerName, $waybill->fullAmount, $waybill->comment]);
        $this->assertSame('2026-11-15 13:10:28', $waybill->createdAt?->format('Y-m-d H:i:s'));
        $this->assertNull($waybill->closedAt);
        $this->assertFalse($waybill->confirmedByBuyer);
        $this->assertSame('12345678910', $waybill->raw['SELLER_TIN']);
        $this->assertCount(2, $waybill->goods);
        $this->assertSame([6309518989, 'ფანქარი HB (test)', 1, '2', '1.5', '3', '02345678', 0], [
            $waybill->goods[0]->id, $waybill->goods[0]->name, $waybill->goods[0]->unitId, $waybill->goods[0]->quantity,
            $waybill->goods[0]->price, $waybill->goods[0]->amount, $waybill->goods[0]->barCode, $waybill->goods[0]->vatType,
        ]);
        $this->assertSame('9.95', $waybill->goods[1]->amount);
    }

    #[Test]
    public function a_deleted_waybill_reads_as_deleted(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill', $this->fixture('get_waybill.deleted.xml')));

        $waybill = $this->service->get(1062469264);

        $this->assertSame(WaybillStatus::Deleted, $waybill->status());
        $this->assertSame(-1, $waybill->statusId);
    }

    #[Test]
    public function a_status_rs_ge_adds_later_is_kept_as_a_number(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill', '<WAYBILL xmlns=""><ID>5</ID><TYPE>9</TYPE><STATUS>77</STATUS></WAYBILL>'));

        $waybill = $this->service->get(5);

        $this->assertSame([9, 77], [$waybill->typeId, $waybill->statusId]);
        $this->assertNull($waybill->type());
        $this->assertNull($waybill->status());
        $this->assertNull($waybill->confirmedByBuyer);
    }

    #[Test]
    public function the_buyers_answer_is_read_as_confirmed_or_not(): void
    {
        $answers = [];
        foreach (['1', '0', '-1', ''] as $mark) {
            $this->transport->push($this->xmlAnswer('get_waybill', '<WAYBILL xmlns=""><ID>5</ID><TYPE>3</TYPE><STATUS>1</STATUS><IS_CONFIRMED>'.$mark.'</IS_CONFIRMED></WAYBILL>'));
            $answers[] = $this->service->get(5)->confirmedByBuyer;
        }

        // 1 confirmed, 0 not yet, -1 rejected, nothing said
        $this->assertSame([true, false, false, null], $answers);
    }

    #[Test]
    public function activation_returns_the_waybill_number(): void
    {
        $this->transport->push((object) ['send_waybillResult' => '1021416743']);
        $this->transport->push((object) ['send_waybil_vdResult' => ' 1021416744 ']);

        $this->assertSame('1021416743', $this->service->activate(1062469264));
        $this->assertSame('1021416744', $this->service->activate(1062469265, new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('Asia/Tbilisi'))));

        $calls = $this->transport->calls();
        $this->assertSame(['method' => 'send_waybill', 'parameters' => self::AUTH + ['waybill_id' => 1062469264]], $calls[0]);
        // rs.ge spells the delayed activation with one l, and takes the date before the id
        $this->assertSame(['method' => 'send_waybil_vd', 'parameters' => self::AUTH + ['begin_date' => '2026-11-16T09:00:00', 'waybill_id' => 1062469265]], $calls[1]);
    }

    #[Test]
    public function activation_that_returns_an_error_code_or_nothing_is_a_failure(): void
    {
        $this->transport->push((object) ['send_waybillResult' => '-1061']);
        $this->transport->push((object) ['send_waybillResult' => '-1045']);
        $this->transport->push((object) ['send_waybillResult' => '']);
        $this->transport->push((object) ['send_waybillResult' => null]);

        $caught = [];
        foreach (range(1, 4) as $attempt) {
            try {
                $this->service->activate(1);
                $caught[] = 'none';
            } catch (RsgeWaybillException $e) {
                $caught[] = [$e::class, $e->errorCode];
            }
        }

        $this->assertSame([
            [RsgeUnreadMessageException::class, -1061],
            [RsgeWaybillException::class, -1045],
            [RsgeWaybillException::class, null],
            [RsgeWaybillException::class, null],
        ], $caught);
    }

    #[Test]
    public function closing_deleting_and_cancelling_succeed_on_one_and_fail_on_anything_else(): void
    {
        $transport = $this->transport;
        $service = $this->service;
        $transport->push((object) ['close_waybillResult' => 1]);
        $transport->push((object) ['close_waybill_vdResult' => 1]);
        $transport->push((object) ['del_waybillResult' => 1]);
        $transport->push((object) ['ref_waybillResult' => 1]);
        $transport->push((object) ['ref_waybill_vdResult' => 1]);

        $service->close(10);
        $service->close(11, new \DateTimeImmutable('2026-11-15 18:30:00', new \DateTimeZone('Asia/Tbilisi')));
        $service->delete(12);
        $service->cancel(13);
        $service->cancel(14, 'შეცდომით გამოიწერა');

        $this->assertSame([
            ['method' => 'close_waybill', 'parameters' => self::AUTH + ['waybill_id' => 10]],
            ['method' => 'close_waybill_vd', 'parameters' => self::AUTH + ['delivery_date' => '2026-11-15T18:30:00', 'waybill_id' => 11]],
            ['method' => 'del_waybill', 'parameters' => self::AUTH + ['waybill_id' => 12]],
            ['method' => 'ref_waybill', 'parameters' => self::AUTH + ['waybill_id' => 13]],
            ['method' => 'ref_waybill_vd', 'parameters' => self::AUTH + ['waybill_id' => 14, 'comment' => 'შეცდომით გამოიწერა']],
        ], $transport->calls());

        foreach ([[-1, RsgeWaybillException::class], [-101, RsgeWaybillException::class], [-100, RsgeAuthenticationException::class], [0, RsgeWaybillException::class], [null, RsgeWaybillException::class], [2, RsgeWaybillException::class]] as [$answer, $expected]) {
            $transport->push((object) ['del_waybillResult' => $answer]);
            try {
                $service->delete(12);
                $this->fail('nothing thrown for '.var_export($answer, true));
            } catch (RsgeException $e) {
                $this->assertInstanceOf($expected, $e, var_export($answer, true));
                $this->assertSame($answer ?? 0, $e->errorCode);
            }
        }
    }

    #[Test]
    public function a_list_is_read_with_its_filters_and_rows(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybills', $this->fixture('get_waybills.xml')));

        $waybills = $this->service->list(new WaybillFilter(
            createdFrom: new \DateTimeImmutable('2026-11-13 00:00:00', new \DateTimeZone('Asia/Tbilisi')),
            createdTo: new \DateTimeImmutable('2026-11-15 23:59:59', new \DateTimeZone('Asia/Tbilisi')),
            types: [WaybillType::WithTransport, WaybillType::WithoutTransport],
            statuses: [WaybillStatus::Active, WaybillStatus::Closed, WaybillStatus::Cancelled],
            serviceUserIds: [382402, 700001],
            comment: 'R-2026-0007',
        ));

        $this->assertSame(['method' => 'get_waybills', 'parameters' => self::AUTH + [
            'itypes' => '2,3',
            'buyer_tin' => '',
            'statuses' => '1,2,-2',
            'car_number' => '',
            'create_date_s' => '2026-11-13T00:00:00',
            'create_date_e' => '2026-11-15T23:59:59',
            'driver_tin' => '',
            'waybill_number' => '',
            's_user_ids' => '382402,700001',
            'comment' => 'R-2026-0007',
        ]], $this->transport->lastCall());

        $this->assertCount(2, $waybills);
        $first = $waybills[0];
        $this->assertSame([1062469264, '1021416743', 382402, 'R-2026-0007', true, false], [$first->id, $first->number, $first->serviceUserId, $first->comment, $first->isActive(), $first->confirmedByBuyer]);
        $this->assertSame([], $first->goods, 'a list row carries no goods');
        $this->assertSame([WaybillStatus::Closed, WaybillType::WithTransport, true, 'AA123AA'], [$waybills[1]->status(), $waybills[1]->type(), $waybills[1]->confirmedByBuyer, $waybills[1]->raw['CAR_NUMBER']]);
    }

    #[Test]
    public function an_empty_list_and_a_range_over_72_hours(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybills', '<WAYBILL_LIST xmlns=""></WAYBILL_LIST>'));
        // what rs.ge answered to a 73-hour range on 2026-11-15
        $this->transport->push($this->xmlAnswer('get_waybills', '<RESULT xmlns=""><STATUS>-1064</STATUS><SUB_WAYBILLS></SUB_WAYBILLS><GOODS_LIST></GOODS_LIST></RESULT>'));
        $filter = new WaybillFilter(new \DateTimeImmutable('2026-11-12', new \DateTimeZone('Asia/Tbilisi')), new \DateTimeImmutable('2026-11-15', new \DateTimeZone('Asia/Tbilisi')));

        $this->assertCount(0, $this->service->list($filter));

        try {
            $this->service->list($filter);
            $this->fail('nothing thrown');
        } catch (RsgeWaybillException $e) {
            $this->assertSame(-1064, $e->errorCode);
            $this->assertStringContainsString('72', (string) $e->errorDescription);
        }
    }

    #[Test]
    public function small_answers_are_read(): void
    {
        $this->transport->push((object) ['what_is_my_ipResult' => '203.0.113.7']);
        $this->transport->push((object) ['get_server_timeResult' => '2026-11-15T13:18:05']);
        $this->transport->push((object) ['get_name_from_tinResult' => 'სატესტო კოდი1']);
        $this->transport->push((object) ['get_name_from_tinResult' => '']);
        $this->transport->push((object) ['get_print_pdfResult' => "%PDF-1.4\nbytes"]);
        $this->transport->push((object) ['get_print_pdfResult' => '']);
        $this->transport->push((object) ['get_server_timeResult' => 'not a time']);

        $this->assertSame('203.0.113.7', $this->service->whatIsMyIp());
        $this->assertSame('2026-11-15 13:18:05', $this->service->serverTime()->format('Y-m-d H:i:s'));
        $this->assertSame('სატესტო კოდი1', $this->service->nameFromTin(' 206322102 '));
        $this->assertNull($this->service->nameFromTin('000'));
        $this->assertSame("%PDF-1.4\nbytes", $this->service->printPdf(7));

        $calls = $this->transport->calls();
        $this->assertSame([[], [], self::AUTH + ['tin' => '206322102']], [$calls[0]['parameters'], $calls[1]['parameters'], $calls[2]['parameters']]);
        $this->assertSame(self::AUTH + ['waybill_id' => 7], $calls[4]['parameters']);

        foreach (['printPdf' => [7], 'serverTime' => []] as $method => $arguments) {
            try {
                $this->service->{$method}(...$arguments);
                $this->fail('nothing thrown by '.$method);
            } catch (RsgeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_answer_that_is_not_xml_is_refused(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill', '<WAYBILL><ID>1</WAYBILL>'));
        $this->transport->push(['get_waybillResult' => null]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->service->get(1);
                $this->fail('nothing thrown');
            } catch (RsgeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_service_user_that_is_not_set_is_refused_before_anything_is_sent(): void
    {
        $refused = 0;
        foreach ([new ServiceUserCredentials('', ''), new ServiceUserCredentials('SHOP_ONE:12345678910', ''), new ServiceUserCredentials('', 'secret')] as $credentials) {
            $service = new WaybillService($this->transport, $credentials);
            foreach ([
                fn () => $service->checkServiceUser(),
                fn () => $service->save($this->draft()),
                fn () => $service->save($this->draft(), sellerUnId: 5),
                fn () => $service->get(1),
                fn () => $service->activate(1),
                fn () => $service->delete(1),
                fn () => $service->types(),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('nothing thrown');
                } catch (RsgeConfigurationException $e) {
                    $this->assertStringContainsString('RSGE_WAYBILL_SU', $e->getMessage());
                    $refused++;
                }
            }
        }

        $this->assertSame(21, $refused);
        $this->assertSame([], $this->transport->calls(), 'rs.ge was never asked');
    }

    #[Test]
    public function a_waybill_is_read_by_its_number(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill_by_number', $this->fixture('get_waybill.saved.xml')));

        $waybill = $this->service->getByNumber(' 1021416743 ');

        $this->assertSame(['method' => 'get_waybill_by_number', 'parameters' => self::AUTH + ['waybill_number' => '1021416743']], $this->transport->lastCall());
        $this->assertSame(1062469264, $waybill->id);
    }

    #[Test]
    public function an_answer_that_holds_no_waybill_is_never_read_as_one(): void
    {
        foreach ([
            '<WAYBILL xmlns=""/>',
            '<WAYBILL xmlns=""><ID>0</ID><STATUS>1</STATUS></WAYBILL>',
            '<RESULT xmlns=""><STATUS>0</STATUS></RESULT>',
            '<RESULT xmlns=""></RESULT>',
            '<RESULT xmlns=""><STATUS>error</STATUS></RESULT>',
            '<SOMETHING xmlns=""><ID>5</ID></SOMETHING>',
        ] as $answer) {
            foreach (['get' => 'get_waybill', 'getByNumber' => 'get_waybill_by_number'] as $method => $operation) {
                $this->transport->push($this->xmlAnswer($operation, $answer));
                try {
                    $this->service->{$method}($method === 'get' ? 5 : '5');
                    $this->fail('read as a waybill: '.$answer);
                } catch (RsgeWaybillException $e) {
                    $this->assertStringContainsString('returned no waybill', $e->getMessage());
                }
            }
        }
    }

    #[Test]
    public function only_a_run_of_digits_is_taken_for_a_waybill_number(): void
    {
        foreach (['0', '000', '-', 'true', '1021416743 ok', 'შეცდომა', '12.5'] as $answer) {
            $this->transport->push((object) ['send_waybillResult' => $answer]);
            try {
                $number = $this->service->activate(1);
                $this->fail('taken for a number: '.var_export($number, true));
            } catch (RsgeWaybillException $e) {
                $this->assertNull($e->errorCode);
            }
        }
    }

    #[Test]
    public function the_activation_date_means_something_only_once_there_is_a_number(): void
    {
        $this->transport->push($this->xmlAnswer('get_waybill', $this->fixture('get_waybill.saved.xml')));
        $this->transport->push($this->xmlAnswer('get_waybill', $this->fixture('get_waybill.deleted.xml')));
        $this->transport->push($this->xmlAnswer('get_waybill', '<WAYBILL xmlns=""><ID>7</ID><TYPE>2</TYPE><STATUS>2</STATUS><WAYBILL_NUMBER>1021416744</WAYBILL_NUMBER>'
            .'<CREATE_DATE>2026-11-15T10:00:00</CREATE_DATE><ACTIVATE_DATE>2026-11-15T11:00:00</ACTIVATE_DATE><BEGIN_DATE>2026-11-15T12:00:00</BEGIN_DATE><CLOSE_DATE>2026-11-15T13:00:00</CLOSE_DATE></WAYBILL>'));

        $saved = $this->service->get(1);
        $deleted = $this->service->get(1);
        $closed = $this->service->get(7);

        // rs.ge fills ACTIVATE_DATE at save; a waybill without a number was never activated
        $this->assertNull($saved->activatedAt);
        $this->assertNull($deleted->activatedAt);
        $this->assertSame(
            ['2026-11-15 10:00:00', '2026-11-15 11:00:00', '2026-11-15 12:00:00', '2026-11-15 13:00:00'],
            array_map(static fn (?\DateTimeImmutable $date): ?string => $date?->format('Y-m-d H:i:s'), [$closed->createdAt, $closed->activatedAt, $closed->beginsAt, $closed->closedAt]),
        );
        $this->assertFalse($closed->isActive(), 'closed is not active');
        $this->assertSame(WaybillStatus::Closed, $closed->status());
    }

    #[Test]
    public function dates_cross_the_wire_as_tbilisi_time_whatever_zone_the_app_keeps(): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->transport->push((object) ['send_waybil_vdResult' => '1021416744']);
        $this->transport->push((object) ['close_waybill_vdResult' => 1]);
        $this->transport->push($this->xmlAnswer('get_waybills', '<WAYBILL_LIST xmlns=""></WAYBILL_LIST>'));
        $this->transport->push($this->xmlAnswer('get_waybill', $this->fixture('get_waybill.saved.xml')));
        $this->transport->push((object) ['get_server_timeResult' => '2026-11-15T13:18:05']);

        // 11:00 UTC is 15:00 in Tbilisi
        $this->service->activate(5, new \DateTimeImmutable('2026-11-15 11:00:00', $utc));
        $this->service->close(5, new \DateTimeImmutable('2026-11-15 12:30:00', $utc));
        $this->service->list(new WaybillFilter(new \DateTimeImmutable('2026-11-14 11:00:00', $utc), new \DateTimeImmutable('2026-11-15 11:00:00', $utc)));
        $waybill = $this->service->get(1062469264);
        $serverTime = $this->service->serverTime();

        $calls = $this->transport->calls();
        $this->assertSame('2026-11-15T15:00:00', $calls[0]['parameters']['begin_date']);
        $this->assertSame('2026-11-15T16:30:00', $calls[1]['parameters']['delivery_date']);
        $this->assertSame(['2026-11-14T15:00:00', '2026-11-15T15:00:00'], [$calls[2]['parameters']['create_date_s'], $calls[2]['parameters']['create_date_e']]);
        // 13:10:28 on rs.ge is 09:10:28 UTC: the same moment, not the same digits in another zone
        $this->assertSame('2026-11-15T09:10:28+00:00', $waybill->createdAt?->setTimezone($utc)->format(DATE_ATOM));
        $this->assertSame('Asia/Tbilisi', $waybill->createdAt?->getTimezone()->getName());
        $this->assertSame('2026-11-15T09:18:05+00:00', $serverTime->setTimezone($utc)->format(DATE_ATOM));
    }

    #[Test]
    public function small_lookups_report_an_error_code_as_an_error(): void
    {
        $this->transport->push((object) ['get_name_from_tinResult' => '-100']);
        $this->transport->push((object) ['get_tin_from_un_idResult' => ' 12345678910 ']);
        $this->transport->push((object) ['get_tin_from_un_idResult' => '']);
        $this->transport->push((object) ['get_tin_from_un_idResult' => '-100']);

        try {
            $this->service->nameFromTin('206322102');
            $this->fail('an error code was returned as a name');
        } catch (RsgeAuthenticationException $e) {
            $this->assertSame(-100, $e->errorCode);
        }
        $this->assertSame('12345678910', $this->service->tinFromUnId(1149251));
        $this->assertSame(['method' => 'get_tin_from_un_id', 'parameters' => self::AUTH + ['un_id' => 1149251]], $this->transport->lastCall());
        $this->assertNull($this->service->tinFromUnId(1));
        $this->expectException(RsgeAuthenticationException::class);
        $this->service->tinFromUnId(1);
    }

    #[Test]
    public function an_empty_answer_to_a_list_is_an_empty_list(): void
    {
        $this->transport->push(['get_waybillsResult' => (object) ['any' => '']]);
        $this->transport->push(['get_waybillsResult' => null]);
        $filter = new WaybillFilter(new \DateTimeImmutable('2026-11-14', new \DateTimeZone('Asia/Tbilisi')), new \DateTimeImmutable('2026-11-15', new \DateTimeZone('Asia/Tbilisi')));

        $this->assertCount(0, $this->service->list($filter));
        $this->assertCount(0, $this->service->list($filter));
    }

    #[Test]
    public function error_codes_are_asked_as_the_service_user_and_read_as_plain_text(): void
    {
        $this->transport->push($this->xmlAnswer('get_error_codes', '<ERROR_CODES xmlns=""><ERROR_CODE><ID>-7</ID><TEXT>&amp;quot;ტრანსპორტირების გარეშე&amp;quot; &amp;amp; სხვა</TEXT><TYPE></TYPE></ERROR_CODE><ERROR_CODE><ID>-8</ID><TEXT>ბ</TEXT><TYPE>0</TYPE></ERROR_CODE></ERROR_CODES>'));

        $codes = $this->service->errorCodes();

        $this->assertSame(['method' => 'get_error_codes', 'parameters' => self::AUTH], $this->transport->lastCall());
        $this->assertSame('"ტრანსპორტირების გარეშე" & სხვა', $codes[0]->text);
        $this->assertNull($codes[0]->type, 'no type given');
        $this->assertSame(0, $codes[1]->type);
    }

    #[Test]
    public function a_new_service_user_asks_rs_ge_for_its_own_un_id(): void
    {
        $this->transport->push((object) ['chek_service_userResult' => true, 'un_id' => 1149251, 's_user_id' => 1]);
        $this->transport->push($this->xmlAnswer('save_waybill', $this->fixture('save_waybill.result.xml')));
        $this->transport->push((object) ['chek_service_userResult' => true, 'un_id' => 731937, 's_user_id' => 2]);
        $this->transport->push($this->xmlAnswer('save_waybill', $this->fixture('save_waybill.result.xml')));

        $this->service->save($this->draft());
        $this->service->withCredentials(new ServiceUserCredentials('OTHER:206322102', 'other'))->save($this->draft());

        $calls = $this->transport->calls();
        $this->assertSame(['chek_service_user', 'save_waybill', 'chek_service_user', 'save_waybill'], array_column($calls, 'method'));
        $second = $calls[3]['parameters']['waybill'];
        $this->assertInstanceOf(XmlNode::class, $second);
        $this->assertStringContainsString('<SELER_UN_ID>731937</SELER_UN_ID>', $second->xml, 'the other taxpayer\'s own number, not the first one\'s');
    }

    #[Test]
    public function passwords_stay_out_of_the_stack_trace_of_a_failure(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $service = new WaybillService($this->transport, new ServiceUserCredentials('SHOP_ONE:12345678910', 'SERVICE-PASSWORD-9'));
            $this->transport->push($this->xmlAnswer('get_waybill', '<RESULT xmlns=""><STATUS>-103</STATUS></RESULT>'));
            $this->transport->push($this->xmlAnswer('get_service_users', '<RESULT xmlns=""><STATUS>-23</STATUS></RESULT>'));
            $this->transport->push((object) ['del_waybillResult' => -101]);
            $this->transport->push($this->xmlAnswer('save_waybill', '<RESULT xmlns=""><STATUS>-1036</STATUS><ID>0</ID></RESULT>'));

            $caught = [];
            foreach ([
                fn () => $service->get(5),
                fn () => $service->serviceUsers('main-user', 'MAIN-PASSWORD-7'),
                fn () => $service->delete(5),
                fn () => $service->save($this->draft(), sellerUnId: 5),
            ] as $operation) {
                try {
                    $operation();
                } catch (RsgeException $e) {
                    $caught[] = $e;
                }
            }

            // and when the call itself does not get through, with the plain-answer operations on the stack
            $down = new WaybillService(new class implements Transport
            {
                public function call(string $method, #[\SensitiveParameter] array $parameters): array|object
                {
                    throw new RsgeTransportException('down');
                }
            }, new ServiceUserCredentials('SHOP_ONE:12345678910', 'SERVICE-PASSWORD-9'));
            foreach ([
                fn () => $down->activate(5),
                fn () => $down->nameFromTin('206322102'),
                fn () => $down->tinFromUnId(5),
                fn () => $down->printPdf(5),
                fn () => $down->types(),
            ] as $operation) {
                try {
                    $operation();
                } catch (RsgeException $e) {
                    $caught[] = $e;
                }
            }

            $this->assertCount(9, $caught);
            foreach ($caught as $exception) {
                // the package's own frames; the test runner's frames hold this test object, whose fake transport remembers every call
                $frames = array_values(array_filter(
                    $exception->getTrace(),
                    static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\') && ! str_starts_with($frame['class'] ?? '', 'Rsge\\Laravel\\Tests\\'),
                ));
                $this->assertNotSame([], $frames);
                $dump = print_r($frames, true).$exception->getMessage().$exception->getTraceAsString();
                $this->assertStringNotContainsString('SERVICE-PASSWORD-9', $dump);
                $this->assertStringNotContainsString('MAIN-PASSWORD-7', $dump);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    #[Test]
    public function credentials_that_are_not_text_are_refused_and_never_quoted(): void
    {
        $service = new WaybillService($this->transport, new ServiceUserCredentials('SHOP_ONE:12345678910', "Sekr\xE9t-9"));

        foreach ([fn () => $service->types(), fn () => $this->service->serviceUsers('main', "Sekr\xE9t-9")] as $operation) {
            try {
                $operation();
                $this->fail('nothing thrown');
            } catch (RsgeConfigurationException $e) {
                $this->assertStringNotContainsString('Sekr', $e->getMessage());
            }
        }
        $this->assertSame([], $this->transport->calls());
    }

    #[Test]
    public function another_service_user_acts_through_the_same_transport(): void
    {
        $this->transport->push((object) ['chek_service_userResult' => true, 'un_id' => 1149251, 's_user_id' => 700002]);

        $other = $this->service->withCredentials(new ServiceUserCredentials('SHOP TWO:12345678910', 'other'));
        $check = $other->checkServiceUser();

        $this->assertNotSame($this->service, $other);
        $this->assertSame(700002, $check->serviceUserId);
        $this->assertSame(['su' => 'SHOP TWO:12345678910', 'sp' => 'other'], $this->transport->lastCall()['parameters'] ?? null);
        $this->assertSame('waybill', $other->name());
    }
}
