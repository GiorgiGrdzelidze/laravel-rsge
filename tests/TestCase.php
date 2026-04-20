<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\RsgeServiceProvider;

/**
 * Base test case for Testbench integration tests.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RsgeServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Rsge' => Rsge::class,
        ];
    }

    /**
     * Rebind BasicCredentials in the container with the given username and password.
     */
    protected function setCredentials(string $u, string $p): void
    {
        $this->app->bind(BasicCredentials::class, fn () => new BasicCredentials($u, $p));
    }
}
