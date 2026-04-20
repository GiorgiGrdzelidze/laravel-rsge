<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Support\Retry;

final class RetryTest extends TestCase
{
    #[Test]
    public function returns_result_on_first_success(): void
    {
        $result = Retry::onSystemError(fn () => 'ok', sleeper: fn (int $us) => null);

        $this->assertSame('ok', $result);
    }

    #[Test]
    public function retries_then_succeeds(): void
    {
        $attempt = 0;

        $result = Retry::onSystemError(
            operation: function () use (&$attempt): string {
                $attempt++;
                if ($attempt < 3) {
                    throw new RsgeSystemException('transient');
                }

                return 'recovered';
            },
            attempts: 3,
            sleeper: fn (int $us) => null,
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $attempt);
    }

    #[Test]
    public function throws_after_exhausting_all_attempts(): void
    {
        $this->expectException(RsgeSystemException::class);
        $this->expectExceptionMessage('always fails');

        Retry::onSystemError(
            operation: fn () => throw new RsgeSystemException('always fails'),
            attempts: 2,
            sleeper: fn (int $us) => null,
        );
    }

    #[Test]
    public function does_not_retry_authentication_exception(): void
    {
        $attempt = 0;

        try {
            Retry::onSystemError(
                operation: function () use (&$attempt): never {
                    $attempt++;
                    throw new RsgeAuthenticationException('bad creds');
                },
                attempts: 3,
                sleeper: fn (int $us) => null,
            );
        } catch (RsgeAuthenticationException) {
            // expected
        }

        $this->assertSame(1, $attempt);
    }

    #[Test]
    public function does_not_retry_transport_exception(): void
    {
        $attempt = 0;

        try {
            Retry::onSystemError(
                operation: function () use (&$attempt): never {
                    $attempt++;
                    throw new RsgeTransportException('network down');
                },
                attempts: 3,
                sleeper: fn (int $us) => null,
            );
        } catch (RsgeTransportException) {
            // expected
        }

        $this->assertSame(1, $attempt);
    }

    #[Test]
    public function sleeper_receives_exponential_backoff_delays(): void
    {
        $delays = [];

        try {
            Retry::onSystemError(
                operation: fn () => throw new RsgeSystemException('fail'),
                attempts: 3,
                baseDelayMs: 100,
                sleeper: function (int $us) use (&$delays): void {
                    $delays[] = $us;
                },
            );
        } catch (RsgeSystemException) {
            // expected
        }

        // 100ms * 2^0 = 100ms = 100_000us, 100ms * 2^1 = 200ms = 200_000us
        $this->assertSame([100_000, 200_000], $delays);
    }
}
