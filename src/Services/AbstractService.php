<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services;

use Rsge\Laravel\Auth\BasicCredentials;
use Rsge\Laravel\Contracts\Service;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Support\ErrorMapper;

/**
 * Base class for rs.ge service implementations.
 */
abstract class AbstractService implements Service
{
    public function __construct(
        protected readonly Transport $transport,
        protected readonly BasicCredentials $credentials,
    ) {}

    /**
     * Merge authentication credentials into the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withAuth(#[\SensitiveParameter] array $payload): array
    {
        return array_merge($this->credentials->toArray(), $payload);
    }

    /**
     * Call a remote method via the transport and assert the response is OK.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|object
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeNotFoundException
     * @throws RsgeSystemException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    protected function invoke(string $method, #[\SensitiveParameter] array $parameters): array|object
    {
        $response = $this->transport->call($method, $parameters);

        // rs.ge SOAP wraps every response in a {MethodName}Result envelope.
        $resultKey = $method.'Result';
        $response = $this->unwrapResultEnvelope($response, $resultKey);

        ErrorMapper::assertOk($response);

        return $response;
    }

    /**
     * Unwrap the {MethodName}Result envelope if present.
     *
     * @param  array<string, mixed>|object  $response
     * @return array<string, mixed>|object
     */
    private function unwrapResultEnvelope(array|object $response, string $resultKey): array|object
    {
        if (is_array($response) && array_key_exists($resultKey, $response)) {
            $inner = $response[$resultKey];

            if (is_array($inner) || is_object($inner)) {
                /** @var array<string, mixed>|object $inner */
                return $inner;
            }
        }

        if (is_object($response) && property_exists($response, $resultKey)) {
            $inner = $response->{$resultKey};

            if (is_array($inner) || is_object($inner)) {
                /** @var array<string, mixed>|object $inner */
                return $inner;
            }
        }

        return $response;
    }
}
