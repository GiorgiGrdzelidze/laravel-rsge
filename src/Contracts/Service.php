<?php

declare(strict_types=1);

namespace Rsge\Laravel\Contracts;

/**
 * Marker interface for rs.ge service implementations.
 */
interface Service
{
    /**
     * Return the canonical name of this service (e.g. 'cash_register').
     */
    public function name(): string;
}
