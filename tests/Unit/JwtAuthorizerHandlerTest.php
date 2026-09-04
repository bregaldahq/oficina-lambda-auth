<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Handler\JwtAuthorizerHandler;
use Oficina\Auth\Secrets\InMemorySecretsProvider;
use Oficina\Auth\Secrets\SecretsProviderInterface;
use Oficina\Auth\Security\JwtProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtAuthorizerHandlerTest extends TestCase
{
    private const SECRET_ID  = 'oficina/test/auth';
    private const JWT_SECRET = 'segredo-de-teste';
    private const NOW        = 1767225600;

    private function handler(?SecretsProviderInterface $secrets = null): JwtAuthorizerHandler
    {
        return new JwtAuthorizerHandler(
            $secrets ?? new InMemorySecretsProvider([
                self::SECRET_ID => ['JWT_SECRET' => self::JWT_SECRET, 'JWT_EXPIRATION' => '3600'],
            ]),
            self::SECRET_ID,
            static fn (): int => self::NOW,
        );
    }

    /** @param array<string, mixed> $payload */
    private function token(array $payload, int $issuedAt = self::NOW, string $secret = self::JWT_SECRET): string
    {
        return (new JwtProvider($secret, 3600, static fn (): int => $issuedAt))->generate($payload);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function event(string $method, string $path, array $headers = []): array
    {
        return [
            'version'        => '2.0',
            'type'           => 'REQUEST',
            'rawPath'        => $path,
            'headers'        => $headers,
            'requestContext' => ['http' => ['method' => $method, 'path' => $path]],
        ];
    }

    public function testAllowsValidCustomerToken(): void
    {
        $token = $this->token(['sub' => 'cust-1', 'role' => 'customer', 'cpf' => '52998224725', 'name' => 'Maria']);

        $result = $this->handler()->handle(
            $this->event('GET', '/api/service-orders/me', ['authorization' => "Bearer {$token}"])
        );

        self::assertTrue($result['isAuthorized']);
        self::assertSame('cust-1', $result['context']['customerId']);
        self::assertSame('customer', $result['context']['role']);
        self::assertSame('cust-1', $result['context']['sub']);
    }

    public function testAllowsValidAdminTokenWithoutCustomerId(): void
    {
        $token = $this->token(['sub' => 'admin', 'role' => 'admin']);

        $result = $this->handler()->handle(
            $this->event('GET', '/api/service-orders', ['Authorization' => "Bearer {$token}"])
        );

        self::assertTrue($result['isAuthorized']);
        self::assertSame('', $result['context']['customerId']);
        self::assertSame('admin', $result['context']['role']);
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $token = $this->token(['sub' => 'admin', 'role' => 'admin']);

        foreach (['authorization', 'Authorization', 'AUTHORIZATION'] as $name) {
            $result = $this->handler()->handle($this->event('GET', '/api/x', [$name => "bearer {$token}"]));
            self::assertTrue($result['isAuthorized'], "falhou para o header {$name}");
        }
    }

    /**
     * `POST /api/auth/login` e' onde o admin OBTEM o token — exigi-lo aqui deixaria
     * o login inalcancavel (secao 5 dos Contratos).
     */
    public function testAllowsLoginRouteWithoutToken(): void
    {
        $result = $this->handler()->handle($this->event('POST', '/api/auth/login'));

        self::assertTrue($result['isAuthorized']);
        self::assertSame('public', $result['context']['role']);
    }

    public function testAllowsLoginRouteWithTrailingSlash(): void
    {
        $result = $this->handler()->handle($this->event('POST', '/api/auth/login/'));

        self::assertTrue($result['isAuthorized']);
    }

    public function testDoesNotOpenTheLoginRouteForOtherMethods(): void
    {
        $result = $this->handler()->handle($this->event('GET', '/api/auth/login'));

        self::assertFalse($result['isAuthorized']);
    }

    public function testDoesNotOpenRoutesThatMerelyStartWithTheLoginPath(): void
    {
        $result = $this->handler()->handle($this->event('POST', '/api/auth/login-as-admin'));

        self::assertFalse($result['isAuthorized']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badHeaderProvider(): iterable
    {
        yield 'sem header'          => [[]];
        yield 'header vazio'        => [['authorization' => '']];
        yield 'so espacos'          => [['authorization' => '   ']];
        yield 'sem prefixo Bearer'  => [['authorization' => 'abc.def.ghi']];
        yield 'esquema errado'      => [['authorization' => 'Basic YWJjOjEyMw==']];
        yield 'Bearer sem token'    => [['authorization' => 'Bearer ']];
    }

    /**
     * @param array<string, string> $headers
     *
     * @dataProvider badHeaderProvider
     */
    public function testDeniesWhenTheAuthorizationHeaderIsUnusable(array $headers): void
    {
        $result = $this->handler()->handle($this->event('GET', '/api/service-orders', $headers));

        self::assertFalse($result['isAuthorized']);
        self::assertSame([], $result['context']);
    }

    public function testDeniesMalformedToken(): void
    {
        $result = $this->handler()->handle(
            $this->event('GET', '/api/x', ['authorization' => 'Bearer nao-e-um-jwt'])
        );

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesTokenSignedWithAnotherSecret(): void
    {
        $token = $this->token(['sub' => 'cust-1', 'role' => 'customer'], self::NOW, 'segredo-do-atacante');

        $result = $this->handler()->handle($this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"]));

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesExpiredToken(): void
    {
        $token = $this->token(['sub' => 'cust-1', 'role' => 'customer'], self::NOW - 7200);

        $result = $this->handler()->handle($this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"]));

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesTokenWithUnknownRole(): void
    {
        $token = $this->token(['sub' => 'cust-1', 'role' => 'superuser']);

        $result = $this->handler()->handle($this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"]));

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesTokenWithoutSub(): void
    {
        $token = $this->token(['role' => 'admin']);

        $result = $this->handler()->handle($this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"]));

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesWhenTheSecretCannotBeRead(): void
    {
        $broken = new class () implements SecretsProviderInterface {
            public function get(string $secretId): array
            {
                throw new RuntimeException('sem acesso ao Secrets Manager');
            }
        };

        $token = $this->token(['sub' => 'admin', 'role' => 'admin']);

        $result = $this->handler($broken)->handle(
            $this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"])
        );

        self::assertFalse($result['isAuthorized']);
    }

    public function testDeniesWhenJwtSecretIsMissingFromTheSecret(): void
    {
        $token = $this->token(['sub' => 'admin', 'role' => 'admin']);

        $result = $this->handler(new InMemorySecretsProvider([self::SECRET_ID => []]))
            ->handle($this->event('GET', '/api/x', ['authorization' => "Bearer {$token}"]));

        self::assertFalse($result['isAuthorized']);
    }

    public function testResponseShapeIsAlwaysTheSimpleResponse(): void
    {
        $result = $this->handler()->handle($this->event('GET', '/api/x'));

        self::assertSame(['isAuthorized', 'context'], array_keys($result));
        self::assertIsBool($result['isAuthorized']);
        self::assertIsArray($result['context']);
    }
}
