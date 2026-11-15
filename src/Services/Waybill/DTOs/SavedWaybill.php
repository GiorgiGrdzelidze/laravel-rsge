<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * What save_waybill answers when it accepted the waybill.
 */
final class SavedWaybill
{
    /**
     * @param  int  $id  The waybill id; every later operation takes it.
     * @param  list<int>  $goodsIds  The ids rs.ge gave the goods lines, in the order they were sent.
     */
    public function __construct(
        public readonly int $id,
        public readonly array $goodsIds,
    ) {}
}
