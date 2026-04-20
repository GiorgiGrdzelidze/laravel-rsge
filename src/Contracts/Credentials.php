<?php

declare(strict_types=1);

namespace Rsge\Laravel\Contracts;

/**
 * Represents a set of credentials for authenticating with rs.ge services.
 */
interface Credentials
{
    /**
     * Return credentials as a key-value array suitable for SOAP parameters.
     *
     * @return array<string, string|int|float|null>
     */
    public function toArray(): array;
}
