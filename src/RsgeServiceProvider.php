<?php

declare(strict_types=1);

namespace Rsge\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Transport\SoapTransport;

/**
 * Registers rs.ge services, transport, and credentials into the Laravel container.
 */
final class RsgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsge.php', 'rsge');

        $this->app->bind(Transport::class, function (Application $app): SoapTransport {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            /** @var array{wsdl?: string, soap_options?: array<string, mixed>} $endpoint */
            $endpoint = $config->get('rsge.endpoints.cash_register', []);

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
        });

        $this->app->bind(BasicCredentials::class, function (Application $app): BasicCredentials {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            /** @var string $username */
            $username = $config->get('rsge.auth.basic.username', '');
            /** @var string $password */
            $password = $config->get('rsge.auth.basic.password', '');

            return new BasicCredentials(
                username: $username,
                password: $password,
            );
        });

        $this->app->singleton(CashRegisterService::class, function (Application $app): CashRegisterService {
            return new CashRegisterService(
                transport: $app->make(Transport::class),
                credentials: $app->make(BasicCredentials::class),
            );
        });

        $this->app->singleton(Rsge::class, function (Application $app): Rsge {
            return new Rsge($app);
        });
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
            Rsge::class,
        ];
    }
}
