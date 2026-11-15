<?php

declare(strict_types=1);

namespace Rsge\Laravel\Support;

use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeUnreadMessageException;
use Rsge\Laravel\Exceptions\RsgeWaybillException;

/**
 * Turns waybill service error codes into exceptions that carry rs.ge's own wording.
 */
final class WaybillErrors
{
    public const WRONG_SERVICE_USER = -100;

    /** @var array<int, array{type: int, text: string}>|null */
    private static ?array $codes = null;

    /**
     * The text rs.ge gives for a code, from the list bundled with the package.
     */
    public static function text(int $code): ?string
    {
        return self::codes()[$code]['text'] ?? null;
    }

    /**
     * @param  list<array{line: int, code: int, text: ?string}>  $lineErrors
     */
    public static function exception(string $operation, int $code, array $lineErrors = []): RsgeException
    {
        $text = self::text($code);
        $message = "rs.ge waybill operation '{$operation}' failed with code {$code}".($text !== null ? ": {$text}" : '.');

        return match ($code) {
            self::WRONG_SERVICE_USER => new RsgeAuthenticationException($message, $code, $text),
            RsgeUnreadMessageException::ERROR_CODE => new RsgeUnreadMessageException($message, $code, $text, $lineErrors),
            default => new RsgeWaybillException($message, $code, $text, $lineErrors),
        };
    }

    /**
     * @return array<int, array{type: int, text: string}>
     */
    private static function codes(): array
    {
        if (self::$codes === null) {
            /** @var array<int, array{type: int, text: string}> $codes */
            $codes = require __DIR__.'/../../resources/waybill-error-codes.php';
            self::$codes = $codes;
        }

        return self::$codes;
    }
}
