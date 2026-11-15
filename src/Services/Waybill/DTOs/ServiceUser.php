<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill\DTOs;

/**
 * A service user of a taxpayer, as get_service_users lists it.
 */
final class ServiceUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $userName,
        public readonly ?int $unId,
        public readonly ?string $ip,
        public readonly ?string $name,
    ) {}

    /**
     * @param  array<string, string>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['ID'] ?? 0),
            userName: $row['USER_NAME'] ?? '',
            unId: isset($row['UN_ID']) && $row['UN_ID'] !== '' ? (int) $row['UN_ID'] : null,
            ip: ($row['IP'] ?? '') !== '' ? $row['IP'] : null,
            name: ($row['NAME'] ?? '') !== '' ? $row['NAME'] : null,
        );
    }

    /**
     * @return array{id: int, user_name: string, un_id: ?int, ip: ?string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->userName,
            'un_id' => $this->unId,
            'ip' => $this->ip,
            'name' => $this->name,
        ];
    }
}
