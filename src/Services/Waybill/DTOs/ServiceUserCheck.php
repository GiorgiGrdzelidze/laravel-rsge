<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * The answer of chek_service_user: whether the service user and password are right, and who it is.
 */
final class ServiceUserCheck
{
    /**
     * @param  ?int  $unId  The taxpayer's unique number; goes into SELER_UN_ID of a waybill.
     * @param  ?int  $serviceUserId  The id rs.ge writes on every waybill this user creates (S_USER_ID).
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?int $unId,
        public readonly ?int $serviceUserId,
    ) {}
}
