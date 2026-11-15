<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeWaybillException;
use Rsge\Laravel\Services\Waybill\Enums\TransportType;
use Rsge\Laravel\Services\Waybill\Enums\WaybillStatus;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Services\Waybill\Requests\GoodsLine;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;
use Rsge\Laravel\Services\Waybill\Requests\WaybillFilter;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Transport\SoapTransport;

/**
 * Talks to the real rs.ge waybill service as a service user of one of rs.ge's two TEST
 * taxpayers. Run with `composer test:live` (see phpunit.live.xml.dist).
 *
 * The write tests touch only waybills they create themselves, from one test taxpayer to
 * the other. Saved ones are deleted and activated ones cancelled, except the one waybill
 * whose test is to close it: that one stays on the test taxpayer, closed.
 */
final class WaybillLiveTest extends TestCase
{
    /** rs.ge's published test taxpayers; error -4002 lets them issue waybills only to each other. */
    private const TEST_TINS = ['206322102', '12345678910'];

    private const WSDL = 'https://services.rs.ge/WayBillService/WayBillService.asmx?WSDL';

    private WaybillService $service;

    private string $sellerTin;

    private string $buyerTin;

    /** @var list<int> waybills this run created and has not yet seen gone */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $su = (string) getenv('RSGE_LIVE_SU');
        $sp = (string) getenv('RSGE_LIVE_SP');
        if ($su === '' || $sp === '') {
            $this->markTestSkipped('RSGE_LIVE_SU / RSGE_LIVE_SP are not set (see phpunit.live.xml.dist).');
        }

        $credentials = new ServiceUserCredentials($su, $sp);
        $tin = (string) $credentials->tin();
        if (! in_array($tin, self::TEST_TINS, true)) {
            $this->fail('The live tests run only for rs.ge\'s test taxpayers ('.implode(', ', self::TEST_TINS).'); "'.$tin.'" is not one of them.');
        }

        $this->sellerTin = $tin;
        $this->buyerTin = $tin === self::TEST_TINS[0] ? self::TEST_TINS[1] : self::TEST_TINS[0];
        $this->service = new WaybillService(
            new SoapTransport(self::WSDL, ['exceptions' => true, 'connection_timeout' => 30, 'cache_wsdl' => WSDL_CACHE_MEMORY]),
            $credentials,
        );

        // the name is only what was typed: ask rs.ge itself whose service user this is
        $check = $this->service->checkServiceUser();
        if (! $check->valid || $check->unId === null) {
            $this->fail('The live service user does not log in.');
        }
        $owner = $this->service->tinFromUnId($check->unId);
        if ($owner !== $tin) {
            $this->fail('rs.ge says this service user belongs to taxpayer "'.$owner.'", not to the test taxpayer "'.$tin.'". Nothing was written.');
        }
    }

    protected function tearDown(): void
    {
        // whatever a failed test left behind is taken off: a saved waybill is deleted, an active one cancelled
        foreach ($this->created as $id) {
            try {
                $status = $this->service->get($id)->statusId;
                if ($status === WaybillStatus::Saved->value) {
                    $this->service->delete($id);
                } elseif ($status === WaybillStatus::Active->value) {
                    $this->service->cancel($id, 'laravel-rsge live test cleanup');
                }
            } catch (\Throwable) {
                fwrite(STDERR, "\n[live] could not clean up test waybill {$id}; look at it on the test portal.\n");
            }
        }
        $this->created = [];

        parent::tearDown();
    }

    private function writesAllowed(): void
    {
        if ((string) getenv('RSGE_LIVE_WRITES') !== '1') {
            $this->markTestSkipped('RSGE_LIVE_WRITES is not 1: the tests that save waybills are off.');
        }
    }

    private function draft(WaybillType $type = WaybillType::WithoutTransport, string $comment = ''): WaybillDraft
    {
        $withTransport = $type === WaybillType::WithTransport;

        return new WaybillDraft(
            type: $type,
            goods: [
                new GoodsLine(name: 'ფანქარი HB (laravel-rsge test)', quantity: 2, price: 1.5, amount: 3, barCode: '02345678'),
                new GoodsLine(name: 'აკრილის საღებავი 75მლ (laravel-rsge test)', quantity: 1, price: 9.95, amount: 9.95, barCode: 'MSCH7501'),
            ],
            buyerTin: $this->buyerTin,
            buyerName: 'სატესტო',
            startAddress: 'თბილისი, ტესტის 1',
            endAddress: $withTransport ? 'ბათუმი, ტესტის 2' : 'თბილისი, ტესტის 1',
            transportType: $withTransport ? TransportType::Road : null,
            driverTin: $withTransport ? $this->sellerTin : '',
            driverName: $withTransport ? 'სატესტო მძღოლი' : '',
            carNumber: $withTransport ? 'AA123AA' : '',
            comment: $comment !== '' ? $comment : 'laravel-rsge live test',
        );
    }

    private function save(WaybillDraft $draft): int
    {
        $saved = $this->service->save($draft);
        $this->created[] = $saved->id;

        return $saved->id;
    }

    // ------------------------------------------------------------------
    // reads
    // ------------------------------------------------------------------

    #[Test]
    public function the_service_user_logs_in_and_belongs_to_a_test_taxpayer(): void
    {
        $check = $this->service->checkServiceUser();

        $this->assertTrue($check->valid);
        $this->assertGreaterThan(0, $check->unId);
        $this->assertGreaterThan(0, $check->serviceUserId);
        $this->assertSame($this->sellerTin, $this->service->tinFromUnId((int) $check->unId));
        $this->assertContains($this->sellerTin, self::TEST_TINS);
        $this->assertNotNull($this->service->nameFromTin($this->buyerTin));
    }

    #[Test]
    public function a_wrong_password_is_told_apart(): void
    {
        $wrong = $this->service->withCredentials(new ServiceUserCredentials((string) getenv('RSGE_LIVE_SU'), 'surely-not-the-password'));

        $this->assertFalse($wrong->checkServiceUser()->valid);
        $this->expectException(RsgeAuthenticationException::class);
        $wrong->types();
    }

    #[Test]
    public function the_enums_and_the_bundled_error_codes_still_match_what_rs_ge_lists(): void
    {
        $this->assertSame(array_map(static fn (WaybillType $t): int => $t->value, WaybillType::cases()), $this->service->types()->pluck('id')->sort()->values()->all());
        $this->assertSame(
            array_map(static fn (TransportType $t): int => $t->value, TransportType::cases()),
            $this->service->transportTypes()->pluck('id')->sort()->values()->all(),
        );
        $units = $this->service->units()->pluck('name', 'id');
        $this->assertSame('ცალი', $units[GoodsLine::UNIT_PIECE] ?? null);
        $this->assertArrayHasKey(GoodsLine::UNIT_OTHER, $units->all());

        /** @var array<int, array{type: int, text: string}> $bundled */
        $bundled = require __DIR__.'/../../resources/waybill-error-codes.php';
        $live = $this->service->errorCodes();
        $liveTexts = $live->mapWithKeys(static fn ($code): array => [$code->id => trim($code->text)])->all();
        $bundledTexts = array_map(static fn (array $entry): string => $entry['text'], $bundled);
        ksort($liveTexts);
        ksort($bundledTexts);
        $this->assertSame($bundledTexts, $liveTexts, 'rs.ge changed its error codes: refresh resources/waybill-error-codes.php');
    }

    #[Test]
    public function the_server_answers_small_questions_and_lists_three_days(): void
    {
        $this->assertMatchesRegularExpression('/^\d{1,3}(\.\d{1,3}){3}$/', $this->service->whatIsMyIp());
        $serverTime = $this->service->serverTime();
        $tbilisi = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tbilisi'));
        $this->assertLessThan(600, abs($serverTime->getTimestamp() - $tbilisi->getTimestamp()), 'rs.ge keeps Tbilisi time');

        $now = $serverTime;
        $list = $this->service->list(new WaybillFilter($now->modify('-71 hours'), $now->modify('+5 minutes')));
        foreach ($list as $waybill) {
            $this->assertGreaterThan(0, $waybill->id);
            $this->assertNotSame(WaybillStatus::Deleted->value, $waybill->statusId, 'deleted waybills are not listed');
        }

        try {
            $this->service->list(new WaybillFilter($now->modify('-73 hours'), $now));
            $this->fail('a range over 72 hours was accepted');
        } catch (RsgeWaybillException $e) {
            $this->assertSame(-1064, $e->errorCode);
        }
    }

    // ------------------------------------------------------------------
    // writes (RSGE_LIVE_WRITES=1)
    // ------------------------------------------------------------------

    #[Test]
    public function a_saved_waybill_is_read_back_and_deleted(): void
    {
        $this->writesAllowed();
        $serviceUserId = $this->service->checkServiceUser()->serviceUserId;

        $id = $this->save($this->draft());
        $saved = $this->service->get($id);

        $this->assertSame(WaybillStatus::Saved, $saved->status());
        $this->assertNull($saved->number);
        $this->assertSame($serviceUserId, $saved->serviceUserId);
        $this->assertSame([$this->buyerTin, 'laravel-rsge live test', 2], [$saved->buyerTin, $saved->comment, count($saved->goods)]);
        $this->assertSame(12.95, (float) $saved->fullAmount);

        $this->service->delete($id);

        $this->assertSame(WaybillStatus::Deleted, $this->service->get($id)->status());
        try {
            $this->service->delete($id);
            $this->fail('a deleted waybill was deleted again');
        } catch (RsgeWaybillException $e) {
            $this->assertNotSame(1, $e->errorCode);
        }
    }

    #[Test]
    public function a_waybill_is_activated_found_in_the_list_printed_and_cancelled(): void
    {
        $this->writesAllowed();
        $serviceUserId = (int) $this->service->checkServiceUser()->serviceUserId;
        $mark = 'laravel-rsge '.bin2hex(random_bytes(4));

        $id = $this->save($this->draft(comment: $mark));
        $number = $this->service->activate($id);
        $active = $this->service->get($id);

        $this->assertMatchesRegularExpression('/^\d+$/', $number);
        $this->assertSame([WaybillStatus::Active, $number], [$active->status(), $active->number]);
        $this->assertSame($id, $this->service->getByNumber($number)->id);

        $now = $this->service->serverTime();
        $mine = $this->service->list(new WaybillFilter($now->modify('-1 hour'), $now->modify('+5 minutes'), serviceUserIds: [$serviceUserId], comment: $mark));
        $this->assertSame([$id], $mine->pluck('id')->all(), 'the list finds the waybill by its comment and its service user');

        $this->assertStringStartsWith('%PDF', $this->service->printPdf($id));

        try {
            $this->service->delete($id);
            $this->fail('an active waybill was deleted');
        } catch (RsgeWaybillException $e) {
            $this->assertNotSame(1, $e->errorCode);
        }

        $this->service->cancel($id, 'laravel-rsge live test');

        $this->assertSame(WaybillStatus::Cancelled, $this->service->get($id)->status());
    }

    #[Test]
    public function a_waybill_with_transport_is_activated_and_closed(): void
    {
        $this->writesAllowed();

        $id = $this->save($this->draft(WaybillType::WithTransport));
        $number = $this->service->activate($id);
        $this->service->close($id);
        $closed = $this->service->get($id);

        $this->assertNotSame('', $number);
        $this->assertSame(WaybillStatus::Closed, $closed->status());
        $this->assertNotNull($closed->closedAt);
        $this->assertSame(['AA123AA', (string) TransportType::Road->value], [$closed->raw['CAR_NUMBER'] ?? null, $closed->raw['TRANS_ID'] ?? null]);
    }

    #[Test]
    public function rs_ge_refuses_what_its_rules_forbid_with_the_codes_the_package_names(): void
    {
        $this->writesAllowed();

        // without transport the two addresses must be the same
        try {
            $id = $this->save(new WaybillDraft(
                type: WaybillType::WithoutTransport,
                goods: [new GoodsLine(name: 'ტესტი', quantity: 1, price: 1, amount: 1)],
                buyerTin: $this->buyerTin,
                buyerName: 'სატესტო',
                startAddress: 'თბილისი',
                endAddress: 'ბათუმი',
            ));
            $this->service->delete($id);
            $this->fail('different addresses without transport were accepted');
        } catch (RsgeWaybillException $e) {
            $this->assertSame(-1036, $e->errorCode);
            $this->assertNotNull($e->errorDescription);
        }

        // an empty waybill is saved but cannot be activated
        $empty = $this->save(new WaybillDraft(type: WaybillType::WithoutTransport, goods: [], buyerTin: $this->buyerTin, buyerName: 'სატესტო', startAddress: 'თბილისი', endAddress: 'თბილისი'));
        try {
            $this->service->activate($empty);
            $this->fail('an empty waybill was activated');
        } catch (RsgeWaybillException $e) {
            $this->assertSame(-1045, $e->errorCode);
        }
        $this->service->delete($empty);
    }
}
