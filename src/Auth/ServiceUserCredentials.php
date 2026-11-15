<?php

declare(strict_types=1);

namespace Rsge\Laravel\Auth;

use Rsge\Laravel\Contracts\Credentials;

/**
 * Credentials of an rs.ge service user (su / sp), as used by the waybill service.
 *
 * A service user name has the form "NAME:TIN", e.g. "SHOP1:206322102".
 */
final class ServiceUserCredentials implements \JsonSerializable, Credentials
{
    public function __construct(
        public readonly string $serviceUser,
        #[\SensitiveParameter]
        public readonly string $servicePassword,
    ) {}

    /**
     * @return array{su: string, sp: string}
     */
    public function toArray(): array
    {
        return [
            'su' => $this->serviceUser,
            'sp' => $this->servicePassword,
        ];
    }

    /**
     * What a dump shows: never the password.
     *
     * @return array{serviceUser: string, servicePassword: string}
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array{serviceUser: string, servicePassword: string}
     */
    public function jsonSerialize(): array
    {
        return ['serviceUser' => $this->serviceUser, 'servicePassword' => $this->servicePassword === '' ? '' : '***'];
    }

    /**
     * Credentials are not stored: a queued job or a cache entry would keep the password in clear.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('rs.ge service user credentials cannot be serialized; pass an id and load the password where it is used.');
    }

    /**
     * Determine whether both the service user and its password are non-empty.
     */
    public function isUsable(): bool
    {
        return $this->serviceUser !== '' && $this->servicePassword !== '';
    }

    /**
     * The taxpayer's identification number carried after the colon, if any.
     */
    public function tin(): ?string
    {
        $position = strrpos($this->serviceUser, ':');
        if ($position === false) {
            return null;
        }

        $tin = trim(substr($this->serviceUser, $position + 1));

        return $tin === '' ? null : $tin;
    }
}
