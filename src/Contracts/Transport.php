<?php

declare(strict_types=1);

namespace Rsge\Laravel\Contracts;

/**
 * Abstraction over the wire protocol used to communicate with rs.ge services.
 */
interface Transport
{
    /**
     * Invoke a remote method with the given parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|object
     */
    public function call(string $method, array $parameters): array|object;
}
