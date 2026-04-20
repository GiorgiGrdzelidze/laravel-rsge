<?php

declare(strict_types=1);

namespace Rsge\Laravel\Transport;

use Rsge\Laravel\Contracts\Transport;

/**
 * In-memory fake transport for deterministic unit testing.
 */
final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>|object|\Throwable> */
    private array $queue = [];

    /** @var list<array{method: string, parameters: array<string, mixed>}> */
    private array $recorded = [];

    /**
     * Enqueue a response to be returned by the next call().
     *
     * @param  array<string, mixed>|object  $response
     */
    public function push(array|object $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * Enqueue an exception to be thrown by the next call().
     */
    public function pushException(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|object
     *
     * @throws \Throwable
     */
    public function call(string $method, array $parameters): array|object
    {
        if ($this->queue === []) {
            throw new \RuntimeException('FakeTransport: response queue is empty.');
        }

        $this->recorded[] = ['method' => $method, 'parameters' => $parameters];

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * Return all recorded calls.
     *
     * @return list<array{method: string, parameters: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->recorded;
    }

    /**
     * Return the last recorded call, or null if none.
     *
     * @return array{method: string, parameters: array<string, mixed>}|null
     */
    public function lastCall(): ?array
    {
        if ($this->recorded === []) {
            return null;
        }

        return $this->recorded[array_key_last($this->recorded)];
    }
}
