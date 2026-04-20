<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Support\ErrorMapper;

final class ErrorMapperTest extends TestCase
{
    #[Test]
    public function successful_response_does_not_throw(): void
    {
        ErrorMapper::assertOk(['ResponseStatus' => true]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function error_code_0_throws_system_exception(): void
    {
        $this->expectException(RsgeSystemException::class);

        ErrorMapper::assertOk([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 0, 'ErrorDescription' => 'სისტემური შეცდომა'],
        ]);
    }

    #[Test]
    public function error_code_1_throws_authentication_exception(): void
    {
        $this->expectException(RsgeAuthenticationException::class);

        ErrorMapper::assertOk([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 1, 'ErrorDescription' => 'მომხმარებელი და პაროლი არასწორია'],
        ]);
    }

    #[Test]
    public function error_code_2_throws_not_found_exception(): void
    {
        $this->expectException(RsgeNotFoundException::class);

        ErrorMapper::assertOk([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 2, 'ErrorDescription' => 'ინფორმაცია არ მოიძებნა'],
        ]);
    }

    #[Test]
    public function unknown_error_code_throws_base_exception(): void
    {
        $this->expectException(RsgeException::class);

        ErrorMapper::assertOk([
            'ResponseStatus' => false,
            'Error' => ['ErrorCode' => 99, 'ErrorDescription' => 'Unknown error'],
        ]);
    }

    #[Test]
    public function string_false_response_status_triggers_error(): void
    {
        $this->expectException(RsgeException::class);

        ErrorMapper::assertOk([
            'ResponseStatus' => 'false',
            'Error' => ['ErrorCode' => 5],
        ]);
    }

    #[Test]
    public function stdclass_response_is_handled(): void
    {
        $response = new \stdClass;
        $response->ResponseStatus = false;
        $error = new \stdClass;
        $error->ErrorCode = 1;
        $error->ErrorDescription = 'bad creds';
        $response->Error = $error;

        $this->expectException(RsgeAuthenticationException::class);
        $this->expectExceptionMessage('bad creds');

        ErrorMapper::assertOk($response);
    }

    #[Test]
    public function missing_description_uses_default_message(): void
    {
        try {
            ErrorMapper::assertOk([
                'ResponseStatus' => false,
                'Error' => ['ErrorCode' => 0],
            ]);
            $this->fail('Expected RsgeSystemException');
        } catch (RsgeSystemException $e) {
            $this->assertSame('rs.ge system error', $e->getMessage());
            $this->assertSame(0, $e->errorCode);
            $this->assertNull($e->errorDescription);
        }
    }

    #[Test]
    public function error_code_as_numeric_string_is_coerced(): void
    {
        try {
            ErrorMapper::assertOk([
                'ResponseStatus' => false,
                'Error' => ['ErrorCode' => '2', 'ErrorDescription' => 'not found'],
            ]);
            $this->fail('Expected RsgeNotFoundException');
        } catch (RsgeNotFoundException $e) {
            $this->assertSame(2, $e->errorCode);
        }
    }

    #[Test]
    public function error_key_present_without_response_status_triggers_error(): void
    {
        $this->expectException(RsgeSystemException::class);

        ErrorMapper::assertOk([
            'Error' => ['ErrorCode' => 0, 'ErrorDescription' => 'system error'],
        ]);
    }
}
