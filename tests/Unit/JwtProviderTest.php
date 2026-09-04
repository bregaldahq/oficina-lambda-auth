<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Security\InvalidTokenException;
use Oficina\Auth\Security\JwtProvider;
use PHPUnit\Framework\TestCase;

final class JwtProviderTest extends TestCase
{
    private const SECRET = 'segredo-de-teste';

    public function testHeaderIsAlwaysAlgThenTyp(): void
    {
        $token = (new JwtProvider(self::SECRET))->generate(['sub' => 'x', 'role' => 'admin']);

        self::assertStringStartsWith('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.', $token);
    }

    public function testSpecificClaimsComeBeforeIssIatExp(): void
    {
        $jwt   = new JwtProvider(self::SECRET, 60, static fn (): int => 1000);
        $token = $jwt->generate(['sub' => 'abc', 'role' => 'admin']);

        [, $payload] = explode('.', $token);
        $json        = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4));

        self::assertSame(
            '{"sub":"abc","role":"admin","iss":"oficina-mecanica-api","iat":1000,"exp":1060}',
            $json
        );
    }

    public function testValidateReturnsClaimsForAValidToken(): void
    {
        $jwt    = new JwtProvider(self::SECRET, 3600, static fn (): int => 1000);
        $claims = $jwt->validate($jwt->generate(['sub' => 'abc', 'role' => 'customer']));

        self::assertSame('abc', $claims['sub']);
        self::assertSame('customer', $claims['role']);
        self::assertSame('oficina-mecanica-api', $claims['iss']);
    }

    public function testRejectsMalformedToken(): void
    {
        $this->expectException(InvalidTokenException::class);

        (new JwtProvider(self::SECRET))->validate('nao.e-um-jwt');
    }

    public function testRejectsTamperedSignature(): void
    {
        $jwt   = new JwtProvider(self::SECRET);
        $token = $jwt->generate(['sub' => 'abc', 'role' => 'admin']);

        $this->expectException(InvalidTokenException::class);

        $jwt->validate(substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A'));
    }

    public function testRejectsTokenSignedWithAnotherSecret(): void
    {
        $token = (new JwtProvider('outro-segredo'))->generate(['sub' => 'abc', 'role' => 'admin']);

        $this->expectException(InvalidTokenException::class);

        (new JwtProvider(self::SECRET))->validate($token);
    }

    public function testRejectsExpiredToken(): void
    {
        $issuer = new JwtProvider(self::SECRET, 60, static fn (): int => 1000);
        $token  = $issuer->generate(['sub' => 'abc', 'role' => 'admin']);

        $later = new JwtProvider(self::SECRET, 60, static fn (): int => 5000);

        $this->expectException(InvalidTokenException::class);

        $later->validate($token);
    }
}
