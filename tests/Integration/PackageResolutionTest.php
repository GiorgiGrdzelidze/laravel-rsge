<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\RsgeServiceProvider;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Tests\TestCase;

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

        $this->assertFileExists($publishedPath);
    }
}
