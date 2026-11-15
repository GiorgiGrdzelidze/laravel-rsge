<?php

declare(strict_types=1);

namespace Rsge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;
use Rsge\Laravel\Services\Waybill\WaybillService;

/**
 * Facade for the rs.ge manager.
 *
 * @method static CashRegisterService cashRegister()
 * @method static WaybillService waybill(?ServiceUserCredentials $as = null)
 *
 * @see \Rsge\Laravel\Rsge
 */
final class Rsge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Rsge\Laravel\Rsge::class;
    }
}
