<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\BasicCredentials;

final class BasicCredentialsTest extends TestCase
{
    #[Test]
    public function to_array_returns_pascal_case_keys(): void
    {
        $creds = new BasicCredentials('user1', 'pass1');

        $this->assertSame([
            'UserName' => 'user1',
            'Password' => 'pass1',
        ], $creds->toArray());
    }

    #[Test]
    public function is_usable_returns_true_when_both_non_empty(): void
    {
        $creds = new BasicCredentials('u', 'p');

        $this->assertTrue($creds->isUsable());
    }

    #[Test]
    public function is_usable_returns_false_when_username_empty(): void
    {
        $this->assertFalse((new BasicCredentials('', 'p'))->isUsable());
    }

    #[Test]
    public function is_usable_returns_false_when_password_empty(): void
    {
        $this->assertFalse((new BasicCredentials('u', ''))->isUsable());
    }
}
