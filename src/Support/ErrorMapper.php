<?php

declare(strict_types=1);

namespace Rsge\Laravel\Support;

use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;

/**
 * Maps rs.ge response error codes to typed exceptions.
 */
final class ErrorMapper
{
    /**
     * Assert that the rs.ge response indicates success.
     *
     * @param  array<string, mixed>|object  $response
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeNotFoundException
     * @throws RsgeSystemException
     * @throws RsgeException
     */
    public static function assertOk(array|object $response): void
    {
        $status = self::extract($response, 'ResponseStatus');
        $error = self::extract($response, 'Error');

        $isFailed = $status === false
            || $status === 'false'
            || $error !== null;

        if (! $isFailed) {
            return;
        }

        $errorCode = null;
        $errorDescription = null;

        if ($error !== null) {
            $rawCode = self::extract($error, 'ErrorCode');
            $errorCode = is_numeric($rawCode) ? (int) $rawCode : null;
            $rawDescription = self::extract($error, 'ErrorDescription');
            $errorDescription = is_string($rawDescription) ? $rawDescription : null;
        }

        $message = $errorDescription ?? self::defaultMessage($errorCode);

        throw match ($errorCode) {
            0 => new RsgeSystemException(
                message: $message,
                errorCode: $errorCode,
                errorDescription: $errorDescription,
            ),
            1 => new RsgeAuthenticationException(
                message: $message,
                errorCode: $errorCode,
                errorDescription: $errorDescription,
            ),
            2 => new RsgeNotFoundException(
                message: $message,
                errorCode: $errorCode,
                errorDescription: $errorDescription,
            ),
            default => new RsgeException(
                message: $message,
                errorCode: $errorCode,
                errorDescription: $errorDescription,
            ),
        };
    }

    private static function defaultMessage(?int $errorCode): string
    {
        return match ($errorCode) {
            0 => 'rs.ge system error',
            1 => 'rs.ge authentication failed',
            2 => 'rs.ge data not found',
            default => 'rs.ge error',
        };
    }

    private static function extract(mixed $source, string $key): mixed
    {
        if (is_array($source) && array_key_exists($key, $source)) {
            return $source[$key];
        }

        if (is_object($source) && property_exists($source, $key)) {
            return $source->{$key};
        }

        return null;
    }
}
