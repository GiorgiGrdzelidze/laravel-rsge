<?php

declare(strict_types=1);

namespace Rsge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Rsge\Laravel\Services\CashRegister\CashRegisterService;

/**
 * Facade for the rs.ge manager.
 *
 * @method static CashRegisterService cashRegister()
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
