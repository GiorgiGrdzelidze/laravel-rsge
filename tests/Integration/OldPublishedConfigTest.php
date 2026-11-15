<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Tests\TestCase;
use Rsge\Laravel\Transport\SoapTransport;

/**
 * An app that published config/rsge.php from v1.0 and then upgraded: its file has no
 * waybill keys, and Laravel merges package defaults only at the top level.
 */
final class OldPublishedConfigTest extends TestCase
{
    /**
     * The app's own config files are loaded here, before any provider registers.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        putenv('RSGE_WAYBILL_SU=SHOP_ONE:206322102');
        putenv('RSGE_WAYBILL_SP=from-the-environment');

        // what v1.0's published file holds, with the app's own values in it
        $app['config']->set('rsge', [
            'auth' => [
                'basic' => ['username' => 'published-user', 'password' => 'published-pass'],
                'oauth' => ['client_id' => '', 'client_secret' => '', 'token_url' => ''],
            ],
            'endpoints' => [
                'cash_register' => ['wsdl' => 'https://example.test/cash?WSDL', 'soap_options' => ['connection_timeout' => 5]],
            ],
            'default_transport' => 'soap',
            'logging' => ['enabled' => false, 'channel' => null],
        ]);
    }

    protected function tearDown(): void
    {
        putenv('RSGE_WAYBILL_SU');
        putenv('RSGE_WAYBILL_SP');

        parent::tearDown();
    }

    #[Test]
    public function the_waybill_service_user_is_read_from_the_environment_all_the_same(): void
    {
        $this->assertSame(['su' => 'SHOP_ONE:206322102', 'sp' => 'from-the-environment'], config('rsge.auth.waybill'));
        $this->assertSame(['su' => 'SHOP_ONE:206322102', 'sp' => 'from-the-environment'], $this->app->make(ServiceUserCredentials::class)->toArray());
    }

    #[Test]
    public function the_waybill_endpoint_is_the_packages_and_the_published_values_stay(): void
    {
        $transport = (new \ReflectionProperty(WaybillService::class, 'transport'))->getValue($this->app->make(WaybillService::class));
        $cashTransport = $this->app->make(Transport::class);

        $this->assertInstanceOf(SoapTransport::class, $transport);
        $this->assertSame('https://services.rs.ge/WayBillService/WayBillService.asmx?WSDL', $transport->wsdl());
        $this->assertInstanceOf(SoapTransport::class, $cashTransport);
        $this->assertSame('https://example.test/cash?WSDL', $cashTransport->wsdl());
        $this->assertSame(['username' => 'published-user', 'password' => 'published-pass'], config('rsge.auth.basic'));
        $this->assertSame(5, config('rsge.endpoints.cash_register.soap_options.connection_timeout'));
        // options the published file does not name come from the package
        $this->assertTrue(config('rsge.endpoints.cash_register.soap_options.exceptions'));
    }
}
