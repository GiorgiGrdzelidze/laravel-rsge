<?php

declare(strict_types=1);

namespace Rsge\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;

/**
 * Manager class providing convenient access to rs.ge service instances.
 */
final class Rsge
{
    public function __construct(
        protected Application $app,
    ) {}

    /**
     * Get the Cash Register service instance.
     */
    public function cashRegister(): CashRegisterService
    {
        return $this->app->make(CashRegisterService::class);
    }
}
