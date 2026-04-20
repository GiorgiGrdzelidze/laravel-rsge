<?php

declare(strict_types=1);

namespace Rsge\Laravel\Auth;

use Rsge\Laravel\Contracts\Credentials;

/**
 * Username/password credentials for rs.ge basic authentication.
 */
final class BasicCredentials implements Credentials
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {}

    /**
     * @return array{UserName: string, Password: string}
     */
    public function toArray(): array
    {
        return [
            'UserName' => $this->username,
            'Password' => $this->password,
        ];
    }

    /**
     * Determine whether both username and password are non-empty.
     */
    public function isUsable(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }
}
