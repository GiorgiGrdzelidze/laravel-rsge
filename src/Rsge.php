<?php

declare(strict_types=1);

namespace Rsge\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Services\Waybill\WaybillService;

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

    /**
     * Get the Waybill service instance.
     *
     * Without an argument it acts as the service user from the configuration; pass
     * credentials to act as another one, e.g. a member of staff.
     */
    public function waybill(?ServiceUserCredentials $as = null): WaybillService
    {
        /** @var WaybillService $service */
        $service = $this->app->make(WaybillService::class);

        return $as === null ? $service : $service->withCredentials($as);
    }
}
