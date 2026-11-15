<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\RsgeServiceProvider;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Tests\TestCase;
use Rsge\Laravel\Transport\SoapTransport;

final class PackageResolutionTest extends TestCase
{
    #[Test]
    public function service_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(RsgeServiceProvider::class),
        );
    }

    #[Test]
    public function config_values_reflect_defaults(): void
    {
        $this->assertSame('', config('rsge.auth.basic.username'));
        $this->assertSame('', config('rsge.auth.basic.password'));
        $this->assertStringContainsString('rs.ge', (string) config('rsge.endpoints.cash_register.wsdl'));
    }

    #[Test]
    public function cash_register_service_resolves_from_container(): void
    {
        $service = $this->app->make(CashRegisterService::class);

        $this->assertInstanceOf(CashRegisterService::class, $service);
    }

    #[Test]
    public function facade_returns_same_singleton_as_container(): void
    {
        $fromContainer = $this->app->make(CashRegisterService::class);
        $fromFacade = Rsge::cashRegister();

        $this->assertSame($fromContainer, $fromFacade);
    }

    #[Test]
    public function config_is_publishable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'rsge-config'])
            ->assertExitCode(0);

        $publishedPath = $this->app->configPath('rsge.php');

        try {
            $this->assertFileExists($publishedPath);
        } finally {
            // the test skeleton lives in vendor and is shared by every later run
            @unlink($publishedPath);
        }
    }

    #[Test]
    public function the_package_config_points_the_waybill_service_at_rs_ge_and_holds_no_user(): void
    {
        /** @var array{auth: array<string, array<string, string>>, endpoints: array<string, array{wsdl: string}>} $config */
        $config = require __DIR__.'/../../config/rsge.php';

        $this->assertSame(['su' => '', 'sp' => ''], $config['auth']['waybill']);
        $this->assertSame('https://services.rs.ge/WayBillService/WayBillService.asmx?WSDL', $config['endpoints']['waybill']['wsdl']);
        $this->assertNotSame($config['endpoints']['cash_register']['wsdl'], $config['endpoints']['waybill']['wsdl']);
    }

    #[Test]
    public function a_configured_waybill_endpoint_is_used(): void
    {
        config(['rsge.endpoints.waybill' => ['wsdl' => 'https://example.test/waybill?WSDL', 'soap_options' => []]]);

        $transport = (new \ReflectionProperty(WaybillService::class, 'transport'))->getValue($this->app->make(WaybillService::class));

        $this->assertInstanceOf(SoapTransport::class, $transport);
        $this->assertSame('https://example.test/waybill?WSDL', $transport->wsdl());
    }

    #[Test]
    public function waybill_service_resolves_once_with_the_configured_service_user(): void
    {
        config(['rsge.auth.waybill.su' => 'SHOP_ONE:206322102', 'rsge.auth.waybill.sp' => 'secret']);

        $service = $this->app->make(WaybillService::class);

        $this->assertInstanceOf(WaybillService::class, $service);
        $this->assertSame($service, $this->app->make(WaybillService::class));
        $this->assertSame($service, Rsge::waybill());
        $this->assertSame('waybill', $service->name());
        $this->assertSame(['su' => 'SHOP_ONE:206322102', 'sp' => 'secret'], $this->app->make(ServiceUserCredentials::class)->toArray());
    }

    #[Test]
    public function the_facade_hands_out_the_waybill_service_as_another_service_user(): void
    {
        $default = Rsge::waybill();
        $asStaff = Rsge::waybill(new ServiceUserCredentials('STAFF:206322102', 'other'));

        $this->assertNotSame($default, $asStaff);
        $this->assertInstanceOf(WaybillService::class, $asStaff);
        $this->assertSame($default, Rsge::waybill(), 'the default one is untouched');
    }

    #[Test]
    public function the_cash_register_service_still_resolves_beside_the_waybill_service(): void
    {
        $this->assertInstanceOf(CashRegisterService::class, Rsge::cashRegister());
        $this->assertInstanceOf(WaybillService::class, Rsge::waybill());
        $this->assertContains(WaybillService::class, (new RsgeServiceProvider($this->app))->provides());
        $this->assertContains(ServiceUserCredentials::class, (new RsgeServiceProvider($this->app))->provides());
    }

    #[Test]
    public function the_waybill_service_is_built_from_its_own_user_options_and_logger(): void
    {
        config([
            'rsge.auth.basic' => ['username' => 'cash-user', 'password' => 'cash-pass'],
            'rsge.auth.waybill' => ['su' => 'SHOP_ONE:206322102', 'sp' => 'secret'],
            'rsge.endpoints.waybill' => ['wsdl' => 'https://example.test/waybill?WSDL', 'soap_options' => ['connection_timeout' => 7]],
            'rsge.logging.enabled' => true,
            'rsge.logging.channel' => null,
        ]);

        $service = $this->app->make(WaybillService::class);
        $read = static fn (object $object, string $property): mixed => (new \ReflectionProperty($object, $property))->getValue($object);
        $credentials = $read($service, 'credentials');
        $transport = $read($service, 'transport');

        $this->assertInstanceOf(ServiceUserCredentials::class, $credentials);
        $this->assertSame(['su' => 'SHOP_ONE:206322102', 'sp' => 'secret'], $credentials->toArray(), 'the waybill user, not the cash register one');
        $this->assertInstanceOf(SoapTransport::class, $transport);
        $this->assertSame(['connection_timeout' => 7], $read($transport, 'options'));
        $this->assertInstanceOf(LoggerInterface::class, $read($transport, 'logger'));

        // acting as a member of staff keeps the transport and takes their credentials
        $staff = new ServiceUserCredentials('STAFF:206322102', 'other');
        $asStaff = Rsge::waybill($staff);
        $this->assertSame($staff, $read($asStaff, 'credentials'));
        $this->assertSame($transport, $read($asStaff, 'transport'));
        $this->assertSame($credentials, $read(Rsge::waybill(), 'credentials'));
    }

    #[Test]
    public function logging_switched_off_gives_the_transport_no_logger(): void
    {
        config(['rsge.logging.enabled' => false]);

        $transport = (new \ReflectionProperty(WaybillService::class, 'transport'))->getValue($this->app->make(WaybillService::class));

        $this->assertInstanceOf(SoapTransport::class, $transport);
        $this->assertNull((new \ReflectionProperty($transport, 'logger'))->getValue($transport));
    }

    #[Test]
    public function a_config_value_left_empty_by_an_unset_variable_is_no_user_and_no_crash(): void
    {
        config(['rsge.auth.waybill' => ['su' => null, 'sp' => null], 'rsge.auth.basic' => ['username' => null, 'password' => null]]);

        $waybill = $this->app->make(ServiceUserCredentials::class);
        $basic = $this->app->make(BasicCredentials::class);

        $this->assertSame(['su' => '', 'sp' => ''], $waybill->toArray());
        $this->assertFalse($waybill->isUsable());
        $this->assertFalse($basic->isUsable());
    }
}
