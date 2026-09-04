<?php

declare(strict_types=1);

namespace Oficina\Auth\Security;

/**
 * Gerador/validador de JWT HS256.
 *
 * ESTE ARQUIVO E' UMA COPIA BYTE A BYTE, no que diz respeito a montagem do token, do
 * `App\Infrastructure\Security\JwtProvider` da aplicacao (secao 4 dos Contratos).
 * Qualquer alteracao aqui PRECISA ser espelhada la' — e vice-versa. O
 * `tests/Contract/JwtContractTest.php` existe justamente para travar isso.
 *
 * Duas diferencas deliberadas, ambas de injecao de dependencia e nenhuma delas
 * afetando os bytes gerados:
 *  - segredo e expiracao chegam pelo construtor (o da aplicacao le `$_ENV` direto);
 *  - o relogio e' injetavel, para o teste de contrato poder fixar o `iat`.
 */
final class JwtProvider
{
    private readonly string $secret;
    private readonly int $expiration;
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(string $secret, int $expiration = 3600, ?callable $clock = null)
    {
        $this->secret     = $secret;
        $this->expiration = $expiration;
        $this->clock      = $clock ?? static fn (): int => time();
    }

    public function getExpiration(): int
    {
        return $this->expiration;
    }

    /**
     * A ordem de montagem importa: as claims especificas vem primeiro no JSON,
     * depois `iss`, `iat`, `exp` (secao 4 dos Contratos).
     *
     * @param array<string, mixed> $payload
     */
    public function generate(array $payload): string
    {
        $now = ($this->clock)();

        $claims = array_merge($payload, [
            'iss' => 'oficina-mecanica-api',
            'iat' => $now,
            'exp' => $now + $this->expiration,
        ]);

        $headerJson = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $bodyJson   = json_encode($claims);
        assert($headerJson !== false && $bodyJson !== false);
        $header    = $this->base64urlEncode($headerJson);
        $body      = $this->base64urlEncode($bodyJson);
        $signature = $this->base64urlEncode(hash_hmac('sha256', "{$header}.{$body}", $this->secret, true));

        return "{$header}.{$body}.{$signature}";
    }

    /** @return array<string, mixed> */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidTokenException("Token JWT inválido: formato incorreto.");
        }

        [$header, $body, $signature] = $parts;

        $expectedSignature = $this->base64urlEncode(
            hash_hmac('sha256', "{$header}.{$body}", $this->secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidTokenException("Token JWT inválido: assinatura incorreta.");
        }

        /** @var mixed $payload */
        $payload = json_decode($this->base64urlDecode($body), true);

        if (!is_array($payload)) {
            throw new InvalidTokenException("Token JWT inválido: payload ilegível.");
        }

        if (!isset($payload['exp']) || (int)$payload['exp'] < ($this->clock)()) {
            throw new InvalidTokenException("Token JWT expirado.");
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
