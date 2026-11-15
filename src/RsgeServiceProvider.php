<?php

declare(strict_types=1);

namespace Rsge\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Transport\SoapTransport;

/**
 * Registers rs.ge services, transport, and credentials into the Laravel container.
 */
final class RsgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsge.php', 'rsge');
        $this->mergeNestedDefaults();

        $this->app->bind(Transport::class, fn (Application $app): SoapTransport => $this->transport($app, 'cash_register'));

        $this->app->bind(BasicCredentials::class, function (Application $app): BasicCredentials {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new BasicCredentials(
                username: $this->text($config->get('rsge.auth.basic.username')),
                password: $this->text($config->get('rsge.auth.basic.password')),
            );
        });

        $this->app->singleton(CashRegisterService::class, function (Application $app): CashRegisterService {
            return new CashRegisterService(
                transport: $app->make(Transport::class),
                credentials: $app->make(BasicCredentials::class),
            );
        });

        $this->app->bind(ServiceUserCredentials::class, function (Application $app): ServiceUserCredentials {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ServiceUserCredentials(
                serviceUser: $this->text($config->get('rsge.auth.waybill.su')),
                servicePassword: $this->text($config->get('rsge.auth.waybill.sp')),
            );
        });

        $this->app->singleton(WaybillService::class, function (Application $app): WaybillService {
            return new WaybillService(
                transport: $this->transport($app, 'waybill'),
                credentials: $app->make(ServiceUserCredentials::class),
            );
        });

        $this->app->singleton(Rsge::class, function (Application $app): Rsge {
            return new Rsge($app);
        });
    }

    /**
     * A config value as text: an env() call with no default hands over null for an unset variable.
     */
    private function text(#[\SensitiveParameter] mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * mergeConfigFrom() fills in only top-level keys, so a config file published by an
     * earlier version hides whatever a later version adds under "auth" or "endpoints".
     * Fill those in too. Like mergeConfigFrom(), this is skipped once the config is
     * cached: the cache is built with it applied.
     */
    private function mergeNestedDefaults(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/rsge.php';
        /** @var array<string, mixed> $current */
        $current = $config->get('rsge', []);

        foreach (['auth', 'endpoints'] as $section) {
            /** @var array<string, mixed> $packaged */
            $packaged = $defaults[$section] ?? [];
            /** @var array<string, mixed> $published */
            $published = is_array($current[$section] ?? null) ? $current[$section] : [];
            $config->set('rsge.'.$section, array_replace_recursive($packaged, $published));
        }
    }

    /**
     * A SOAP transport for one of the configured endpoints.
     */
    private function transport(Application $app, string $endpointName): SoapTransport
    {
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);

        /** @var array{wsdl?: string, soap_options?: array<string, mixed>} $endpoint */
        $endpoint = $config->get('rsge.endpoints.'.$endpointName, []);

        $wsdl = $endpoint['wsdl'] ?? null;
        /** @var array<string, mixed> $options */
        $options = $endpoint['soap_options'] ?? [];

        $logger = null;
        /** @var bool $loggingEnabled */
        $loggingEnabled = $config->get('rsge.logging.enabled', false);

        if ($loggingEnabled) {
            /** @var string|null $channel */
            $channel = $config->get('rsge.logging.channel');

            if ($channel !== null) {
                /** @var LogManager $logManager */
                $logManager = $app->make('log');
                $logger = $logManager->channel($channel);
            } else {
                $logger = $app->make(LoggerInterface::class);
            }
        }

        return new SoapTransport(
            wsdl: $wsdl,
            options: $options,
            logger: $logger,
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsge.php' => config_path('rsge.php'),
            ], 'rsge-config');
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            Transport::class,
            BasicCredentials::class,
            CashRegisterService::class,
            ServiceUserCredentials::class,
            WaybillService::class,
            Rsge::class,
        ];
    }
}
