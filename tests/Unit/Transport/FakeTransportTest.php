<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Transport\FakeTransport;

final class FakeTransportTest extends TestCase
{
    #[Test]
    public function returns_responses_in_fifo_order(): void
    {
        $transport = new FakeTransport;
        $transport->push(['first' => true]);
        $transport->push(['second' => true]);

        $this->assertSame(['first' => true], $transport->call('A', []));
        $this->assertSame(['second' => true], $transport->call('B', []));
    }

    #[Test]
    public function records_all_calls(): void
    {
        $transport = new FakeTransport;
        $transport->push(['ok' => true]);
        $transport->push(['ok' => true]);

        $transport->call('Method1', ['key' => 'val1']);
        $transport->call('Method2', ['key' => 'val2']);

        $this->assertCount(2, $transport->calls());
        $this->assertSame('Method1', $transport->calls()[0]['method']);
        $this->assertSame(['key' => 'val2'], $transport->calls()[1]['parameters']);
    }

    #[Test]
    public function last_call_returns_most_recent(): void
    {
        $transport = new FakeTransport;
        $transport->push(['ok' => true]);
        $transport->push(['ok' => true]);

        $this->assertNull($transport->lastCall());

        $transport->call('First', []);
        $transport->call('Second', ['p' => 1]);

        $this->assertSame('Second', $transport->lastCall()['method']);
    }

    #[Test]
    public function throws_when_queue_is_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response queue is empty');

        (new FakeTransport)->call('Method', []);
    }

    #[Test]
    public function push_exception_throws_on_call(): void
    {
        $transport = new FakeTransport;
        $transport->pushException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $transport->call('Method', []);
    }

    #[Test]
    public function exception_is_recorded_in_calls(): void
    {
        $transport = new FakeTransport;
        $transport->pushException(new \RuntimeException('fail'));

        try {
            $transport->call('Method', ['a' => 1]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $transport->calls());
        $this->assertSame('Method', $transport->calls()[0]['method']);
    }
}
