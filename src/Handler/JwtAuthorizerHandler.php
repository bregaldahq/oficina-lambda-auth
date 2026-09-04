<?php

declare(strict_types=1);

namespace Oficina\Auth\Handler;

use Oficina\Auth\Secrets\SecretsProviderInterface;
use Oficina\Auth\Security\InvalidTokenException;
use Oficina\Auth\Security\JwtProvider;
use Throwable;

/**
 * Lambda authorizer REQUEST do HTTP API, com `enable_simple_responses = true`.
 *
 * Retorna sempre `{"isAuthorized": bool, "context": {...}}` (secao 5 dos Contratos).
 * Roda FORA da VPC: nao toca no banco, so' valida assinatura e expiracao do JWT com
 * o segredo lido do Secrets Manager (cacheado entre invocacoes).
 *
 * A aplicacao revalida o token localmente — este authorizer e' a primeira barreira,
 * nunca a unica.
 */
final class JwtAuthorizerHandler
{
    /**
     * Rotas liberadas sem token. `POST /api/auth/login` e' onde o admin OBTEM o
     * token; exigi-lo aqui tornaria o login inalcancavel.
     *
     * @var list<array{method: string, path: string}>
     */
    private const PUBLIC_ROUTES = [
        ['method' => 'POST', 'path' => '/api/auth/login'],
    ];

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly SecretsProviderInterface $secrets,
        private readonly string $authSecretId,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{isAuthorized: bool, context: array<string, string>}
     */
    public function handle(array $event): array
    {
        try {
            if ($this->isPublicRoute($event)) {
                return self::allow(['role' => 'public', 'customerId' => '']);
            }

            $token = $this->extractToken($event);

            if ($token === null) {
                return self::deny();
            }

            $secret    = $this->secrets->get($this->authSecretId);
            $jwtSecret = $secret['JWT_SECRET'] ?? null;

            if (!is_string($jwtSecret) || $jwtSecret === '') {
                return self::deny();
            }

            $claims = (new JwtProvider($jwtSecret, 3600, $this->clock))->validate($token);

            /** @var mixed $role */
            $role = $claims['role'] ?? null;
            /** @var mixed $sub */
            $sub = $claims['sub'] ?? null;

            if (!is_string($role) || !in_array($role, ['customer', 'admin'], true)) {
                return self::deny();
            }

            if (!is_string($sub) || $sub === '') {
                return self::deny();
            }

            return self::allow([
                'customerId' => $role === 'customer' ? $sub : '',
                'role'       => $role,
                'sub'        => $sub,
            ]);
        } catch (InvalidTokenException) {
            return self::deny();
        } catch (Throwable $e) {
            error_log(json_encode([
                'level'             => 'error',
                'message'           => 'authorizer.failed',
                'service'           => 'oficina-jwt-authorizer',
                'exception_class'   => $e::class,
                'exception_message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE) ?: 'authorizer.failed');

            return self::deny();
        }
    }

    /** @param array<string, mixed> $event */
    private function isPublicRoute(array $event): bool
    {
        $method = strtoupper($this->requestMethod($event));
        $path   = $this->requestPath($event);

        foreach (self::PUBLIC_ROUTES as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $event */
    private function requestMethod(array $event): string
    {
        /** @var mixed $http */
        $http = is_array($event['requestContext'] ?? null) ? $event['requestContext']['http'] ?? null : null;

        if (is_array($http) && is_string($http['method'] ?? null)) {
            return $http['method'];
        }

        return '';
    }

    /** @param array<string, mixed> $event */
    private function requestPath(array $event): string
    {
        /** @var mixed $http */
        $http = is_array($event['requestContext'] ?? null) ? $event['requestContext']['http'] ?? null : null;

        $path = null;

        if (is_array($http) && is_string($http['path'] ?? null)) {
            $path = $http['path'];
        } elseif (is_string($event['rawPath'] ?? null)) {
            $path = $event['rawPath'];
        }

        if ($path === null) {
            return '';
        }

        // Normaliza barra final: /api/auth/login/ e /api/auth/login sao a mesma rota.
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** @param array<string, mixed> $event */
    private function extractToken(array $event): ?string
    {
        $headers = $event['headers'] ?? null;

        if (!is_array($headers)) {
            return null;
        }

        $value = null;

        foreach ($headers as $name => $headerValue) {
            if (is_string($name) && strtolower($name) === 'authorization' && is_string($headerValue)) {
                $value = $headerValue;
                break;
            }
        }

        if ($value === null || trim($value) === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($value), $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * @param array<string, string> $context
     *
     * @return array{isAuthorized: bool, context: array<string, string>}
     */
    private static function allow(array $context): array
    {
        return ['isAuthorized' => true, 'context' => $context];
    }

    /** @return array{isAuthorized: bool, context: array<string, string>} */
    private static function deny(): array
    {
        return ['isAuthorized' => false, 'context' => []];
    }
}
