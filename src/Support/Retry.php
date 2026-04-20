<?php

declare(strict_types=1);

namespace Rsge\Laravel\Support;

use Rsge\Laravel\Exceptions\RsgeSystemException;

/**
 * Retry helper with exponential backoff for transient rs.ge system errors.
 */
final class Retry
{
    /**
     * Retry the given operation on transient RsgeSystemException.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  (callable(int): void)|null  $sleeper  Custom sleep function (receives microseconds).
     * @return T
     *
     * @throws RsgeSystemException When all retry attempts are exhausted.
     */
    public static function onSystemError(
        callable $operation,
        int $attempts = 3,
        int $baseDelayMs = 200,
        ?callable $sleeper = null,
    ): mixed {
        $sleeper ??= usleep(...);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation();
            } catch (RsgeSystemException $e) {
                $lastException = $e;

                if ($attempt < $attempts) {
                    $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                    $sleeper($delayMs * 1000);
                }
            }
        }

        /** @var RsgeSystemException $lastException */
        throw $lastException;
    }
}
