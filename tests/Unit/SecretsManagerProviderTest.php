<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Secrets\InMemorySecretsProvider;
use Oficina\Auth\Secrets\SecretsException;
use PHPUnit\Framework\TestCase;

final class SecretsManagerProviderTest extends TestCase
{
    public function testInMemoryProviderReturnsTheStoredSecret(): void
    {
        $provider = new InMemorySecretsProvider(['a' => ['JWT_SECRET' => 's']]);

        self::assertSame(['JWT_SECRET' => 's'], $provider->get('a'));
    }

    public function testInMemoryProviderThrowsForUnknownSecret(): void
    {
        $this->expectException(SecretsException::class);

        (new InMemorySecretsProvider([]))->get('nao-existe');
    }
}
