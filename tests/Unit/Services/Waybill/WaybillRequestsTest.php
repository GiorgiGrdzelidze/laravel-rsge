<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Services\Waybill;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Services\Waybill\Enums\TransportType;
use Rsge\Laravel\Services\Waybill\Enums\VatType;
use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Services\Waybill\Requests\GoodsLine;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;
use Rsge\Laravel\Services\Waybill\Requests\WaybillFilter;
use Rsge\Laravel\Support\WaybillXml;

final class WaybillRequestsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function tags(string $xml): array
    {
        $element = WaybillXml::parse($xml);

        return WaybillXml::fields($element);
    }

    #[Test]
    public function a_goods_line_is_written_in_the_order_rs_ge_accepted(): void
    {
        $line = new GoodsLine(name: '  ქაღალდი A4 <500> & "co"  ', quantity: '2,5', price: 10, amount: 25.0, barCode: 'A&B', unitId: 99, unitText: 'შეკვრა', vatType: VatType::Exempt, exciseId: 4, id: 9);

        $this->assertSame(
            '<GOODS><ID>9</ID><W_NAME>ქაღალდი A4 &lt;500&gt; &amp; &quot;co&quot;</W_NAME><UNIT_ID>99</UNIT_ID><UNIT_TXT>შეკვრა</UNIT_TXT><QUANTITY>2.5</QUANTITY><PRICE>10</PRICE><STATUS>1</STATUS><AMOUNT>25</AMOUNT><BAR_CODE>A&amp;B</BAR_CODE><A_ID>4</A_ID><VAT_TYPE>2</VAT_TYPE></GOODS>',
            $line->toXml(),
        );
        $this->assertStringContainsString('<STATUS>-1</STATUS>', (new GoodsLine(name: 'x', quantity: 1, price: 1, amount: 1, id: 9, remove: true))->toXml());
    }

    #[Test]
    public function a_goods_line_without_a_name_or_with_an_unnamed_other_unit_is_refused(): void
    {
        foreach ([
            fn () => new GoodsLine(name: '   ', quantity: 1, price: 1, amount: 1),
            fn () => new GoodsLine(name: 'x', quantity: 1, price: 1, amount: 1, unitId: GoodsLine::UNIT_OTHER),
            fn () => new GoodsLine(name: 'x', quantity: 1, price: 1, amount: 1, unitId: GoodsLine::UNIT_OTHER, unitText: ' '),
            fn () => (new GoodsLine(name: 'x', quantity: 'two', price: 1, amount: 1))->toXml(),
            fn () => (new GoodsLine(name: 'x', quantity: NAN, price: 1, amount: 1))->toXml(),
        ] as $make) {
            try {
                $make();
                $this->fail('nothing thrown');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function numbers_use_a_point_and_never_an_exponent_or_a_thousands_separator(): void
    {
        $this->assertSame(
            ['0', '3', '1.5', '9.95', '1234567.8', '0.0001', '0', '12.35', '2.5', '100'],
            array_map(WaybillXml::number(...), [0, 3, 1.5, 9.95, 1234567.80, 0.0001, 0.00001, '12.3500', ' 2,5 ', 1e2]),
        );
    }

    #[Test]
    public function a_waybill_with_transport_carries_the_driver_the_car_and_the_transport_type(): void
    {
        $draft = new WaybillDraft(
            type: WaybillType::WithTransport,
            goods: [
                new GoodsLine(name: 'ა', quantity: 2, price: 1.1, amount: 2.2),
                new GoodsLine(name: 'ბ', quantity: 1, price: 0.1, amount: 0.1),
                new GoodsLine(name: 'წაშლილი', quantity: 1, price: 50, amount: 50, id: 7, remove: true),
            ],
            buyerTin: ' 01001011111 ',
            buyerName: 'John <Smith>',
            buyerIsResident: false,
            startAddress: 'თბილისი',
            endAddress: 'ბათუმი',
            transportType: TransportType::Other,
            transportText: 'ეტლი',
            driverTin: '12345678910',
            driverName: 'მძღოლი',
            driverIsResident: false,
            carNumber: ' AA123AA ',
            transportCost: '15,50',
            transportCostPayer: WaybillDraft::COST_PAID_BY_SELLER,
            beginDate: new \DateTimeImmutable('2026-11-16 09:05:00', new \DateTimeZone('Asia/Tbilisi')),
            comment: 'R-2026-0007',
            transporterTin: '206322102',
            parentId: 55,
            id: 1062469264,
        );
        $xml = $draft->toXml(1149251);
        $tags = $this->tags($xml);

        $this->assertStringStartsWith('<WAYBILL xmlns=""><SUB_WAYBILLS></SUB_WAYBILLS><GOODS_LIST><GOODS>', $xml);
        $this->assertSame(3, substr_count($xml, '<GOODS>'));
        $this->assertSame([
            'SUB_WAYBILLS' => '', 'ID' => '1062469264', 'TYPE' => '2', 'BUYER_TIN' => '01001011111', 'CHEK_BUYER_TIN' => '0', 'BUYER_NAME' => 'John <Smith>',
            'START_ADDRESS' => 'თბილისი', 'END_ADDRESS' => 'ბათუმი', 'DRIVER_TIN' => '12345678910', 'CHEK_DRIVER_TIN' => '0', 'DRIVER_NAME' => 'მძღოლი',
            'TRANSPORT_COAST' => '15.5', 'RECEPTION_INFO' => '', 'RECEIVER_INFO' => '', 'DELIVERY_DATE' => '', 'STATUS' => '0', 'SELER_UN_ID' => '1149251',
            'PAR_ID' => '55', 'FULL_AMOUNT' => '2.3', 'CAR_NUMBER' => 'AA123AA', 'WAYBILL_NUMBER' => '', 'S_USER_ID' => '', 'BEGIN_DATE' => '2026-11-16T09:05:00',
            'TRAN_COST_PAYER' => '2', 'TRANS_ID' => '4', 'TRANS_TXT' => 'ეტლი', 'COMMENT' => 'R-2026-0007', 'TRANSPORTER_TIN' => '206322102',
        ], $tags);
        $this->assertSame('2.3', $draft->fullAmount(), 'the removed line is not counted, and 2.2 + 0.1 is 2.3');
    }

    #[Test]
    public function a_waybill_handed_to_a_carrier_is_stored_as_sent_to_it_with_no_driver_and_no_car(): void
    {
        $carrier = fn (array $over = []): WaybillDraft => new WaybillDraft(...($over + [
            'type' => WaybillType::WithTransport,
            'goods' => [new GoodsLine(name: 'ა', quantity: 1, price: 2, amount: 2)],
            'buyerTin' => '01001011111',
            'startAddress' => 'თბილისი',
            'endAddress' => 'ბათუმი',
            'transportType' => TransportType::Carrier,
            'transporterTin' => ' 206322102 ',
            'handedToCarrier' => true,
        ]));

        $tags = $this->tags($carrier()->toXml(1149251));
        $expected = ['TYPE' => '2', 'STATUS' => '8', 'TRANS_ID' => '7', 'TRANSPORTER_TIN' => '206322102', 'DRIVER_TIN' => '', 'CHEK_DRIVER_TIN' => '', 'DRIVER_NAME' => '', 'CAR_NUMBER' => '', 'END_ADDRESS' => 'ბათუმი'];
        foreach ($expected as $tag => $value) {
            $this->assertSame($value, $tags[$tag] ?? null, $tag);
        }
        $this->assertSame('8', $this->tags($carrier(['type' => WaybillType::Internal])->toXml(1))['STATUS']);
        // the same waybill only saved names the carrier and stays the seller's to send
        $this->assertSame('0', $this->tags($carrier(['handedToCarrier' => false])->toXml(1))['STATUS']);

        $refused = [
            'a waybill without transport' => ['type' => WaybillType::WithoutTransport],
            'a distribution waybill' => ['type' => WaybillType::Distribution],
            'a return' => ['type' => WaybillType::Return],
            'a sub-waybill' => ['type' => WaybillType::SubWaybill],
            'another transport type' => ['transportType' => TransportType::Road],
            'no transport type' => ['transportType' => null],
            'no carrier' => ['transporterTin' => '  '],
            'a driver' => ['driverTin' => '01001011111'],
            'a driver by name' => ['driverName' => 'მძღოლი'],
            'a car' => ['carNumber' => 'AA123AA'],
        ];
        foreach ($refused as $what => $over) {
            try {
                $carrier($over);
                $this->fail("handed to a carrier with {$what}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_waybill_without_its_own_start_date_starts_now_in_tbilisi_time(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tbilisi'));
        $tags = $this->tags((new WaybillDraft(type: WaybillType::WithoutTransport, goods: [new GoodsLine(name: 'ა', quantity: 1, price: 1, amount: 1)]))->toXml(1));
        $after = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tbilisi'));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $tags['BEGIN_DATE']);
        $this->assertGreaterThanOrEqual($before->format('Y-m-d\TH:i:s'), $tags['BEGIN_DATE']);
        $this->assertLessThanOrEqual($after->format('Y-m-d\TH:i:s'), $tags['BEGIN_DATE']);
        // a driver that is not given has no resident mark, and no carrier tag is sent
        $this->assertSame(['', ''], [$tags['DRIVER_TIN'], $tags['CHEK_DRIVER_TIN']]);
        $this->assertArrayNotHasKey('TRANSPORTER_TIN', $tags);
        $this->assertSame(['1', '', ''], [$tags['TRAN_COST_PAYER'], $tags['TRANS_ID'], $tags['PAR_ID']]);
    }

    #[Test]
    public function text_that_would_break_the_xml_is_made_safe(): void
    {
        $draft = new WaybillDraft(
            type: WaybillType::WithoutTransport,
            goods: [new GoodsLine(name: "ფანქარი\x00\x0B</W_NAME><ID>9</ID>", quantity: 1, price: 1, amount: 1)],
            buyerName: ']]></BUYER_NAME><STATUS>1</STATUS>',
            comment: "ხაზი 1\nხაზი 2 'x' \"y\"",
        );
        $xml = $draft->toXml(1);
        $root = WaybillXml::parse($xml);
        $tags = WaybillXml::fields($root);

        $this->assertSame(']]></BUYER_NAME><STATUS>1</STATUS>', $tags['BUYER_NAME']);
        $this->assertSame('0', $tags['STATUS']);
        $this->assertSame("ხაზი 1\nხაზი 2 'x' \"y\"", $tags['COMMENT']);
        $this->assertSame(1, substr_count($xml, '<GOODS>'));
        $this->assertStringContainsString('<W_NAME>ფანქარი&lt;/W_NAME&gt;&lt;ID&gt;9&lt;/ID&gt;</W_NAME>', $xml);
    }

    #[Test]
    public function a_resident_driver_and_both_info_fields_go_to_their_own_tags(): void
    {
        $tags = $this->tags((new WaybillDraft(
            type: WaybillType::WithTransport,
            goods: [new GoodsLine(name: 'ა', quantity: 1, price: 1, amount: 1)],
            driverTin: '01001011111',
            driverName: 'მძღოლი',
            receptionInfo: 'მიმწოდებლის ინფორმაცია',
            receiverInfo: 'მიმღების ინფორმაცია',
        ))->toXml(1));

        $this->assertSame(['01001011111', '1'], [$tags['DRIVER_TIN'], $tags['CHEK_DRIVER_TIN']], 'a driver is a resident unless said otherwise');
        $this->assertSame(['მიმწოდებლის ინფორმაცია', 'მიმღების ინფორმაცია'], [$tags['RECEPTION_INFO'], $tags['RECEIVER_INFO']]);
    }

    #[Test]
    public function a_number_rs_ge_could_not_read_is_refused_when_the_line_is_made(): void
    {
        foreach ([
            fn () => new GoodsLine(name: 'x', quantity: '1 234', price: 1, amount: 1),
            fn () => new GoodsLine(name: 'x', quantity: 1, price: 'ათი', amount: 1),
            fn () => new GoodsLine(name: 'x', quantity: 1, price: 1, amount: INF),
            fn () => new GoodsLine(name: 'x', quantity: -INF, price: 1, amount: 1),
            fn () => new GoodsLine(name: 'x', quantity: NAN, price: 1, amount: 1),
            fn () => new WaybillDraft(type: WaybillType::Internal, goods: [], transportCost: 'უფასო'),
        ] as $make) {
            try {
                $make();
                $this->fail('nothing thrown');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function text_cut_in_the_middle_of_a_letter_keeps_what_is_left_of_it(): void
    {
        $cut = substr('აკრილის საღებავი', 0, 20);
        $this->assertFalse(mb_check_encoding($cut, 'UTF-8'));

        $xml = (new WaybillDraft(
            type: WaybillType::WithoutTransport,
            goods: [new GoodsLine(name: $cut, quantity: 1, price: 1, amount: 1)],
            comment: 'R-2026-0007 '.substr('ქვითარი', 0, 5),
        ))->toXml(1);
        $root = WaybillXml::parse($xml);

        $this->assertStringContainsString('<W_NAME>აკრილი?</W_NAME>', $xml);
        $this->assertSame('R-2026-0007 ქ?', WaybillXml::fields($root)['COMMENT']);

        // a name made of nothing but broken bytes or control characters is no name
        foreach (["\xC3", "\x00\x01", " \x0B "] as $name) {
            try {
                new GoodsLine(name: $name, quantity: 1, price: 1, amount: 1);
                $this->fail('accepted '.bin2hex($name));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function characters_xml_allows_are_kept_and_the_ones_it_forbids_are_dropped(): void
    {
        $this->assertSame("a\tb\nc\rd", WaybillXml::escape("a\tb\nc\rd"));
        $this->assertSame('ფერი 🎨 𝄞', WaybillXml::escape('ფერი 🎨 𝄞'));
        $this->assertSame('ab', WaybillXml::escape("a\u{FFFE}\u{FFFF}b"));
        $this->assertSame('ab', WaybillXml::escape("a\x00\x08\x0B\x0C\x1Fb"));
    }

    #[Test]
    public function a_draft_takes_only_goods_lines_and_a_known_payer(): void
    {
        foreach ([
            fn () => new WaybillDraft(type: WaybillType::Internal, goods: ['not a line']), // @phpstan-ignore argument.type
            fn () => new WaybillDraft(type: WaybillType::Internal, goods: [], transportCostPayer: 3),
        ] as $make) {
            try {
                $make();
                $this->fail('nothing thrown');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('0', (new WaybillDraft(type: WaybillType::Internal, goods: []))->fullAmount());
    }

    #[Test]
    public function a_filter_needs_a_range_that_does_not_end_before_it_starts(): void
    {
        $filter = new WaybillFilter(new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('Asia/Tbilisi')), new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('Asia/Tbilisi')), statuses: [WaybillStatus::Saved], buyerTin: ' 206322102 ', waybillNumber: ' 1021416743 ', carNumber: ' AA123AA ', driverTin: ' 1 ');

        $this->assertSame([
            'itypes' => '', 'buyer_tin' => '206322102', 'statuses' => ',0,', 'car_number' => 'AA123AA', 'create_date_s' => '2026-11-15T10:00:00', 'create_date_e' => '2026-11-15T10:00:00',
            'driver_tin' => '1', 'waybill_number' => '1021416743', 's_user_ids' => '', 'comment' => '',
        ], $filter->toSoap());

        $this->expectException(\InvalidArgumentException::class);
        new WaybillFilter(new \DateTimeImmutable('2026-11-15 10:00:01', new \DateTimeZone('Asia/Tbilisi')), new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('Asia/Tbilisi')));
    }
}
