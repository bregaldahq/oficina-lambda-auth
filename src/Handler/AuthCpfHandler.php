<?php

declare(strict_types=1);

namespace Oficina\Auth\Handler;

use Oficina\Auth\Domain\Cpf;
use Oficina\Auth\Domain\InvalidCpfException;
use Oficina\Auth\Repository\CustomerRepositoryInterface;
use Oficina\Auth\Secrets\SecretsProviderInterface;
use Oficina\Auth\Security\JwtProvider;
use Throwable;

/**
 * `POST /auth/cpf` — identificacao do cliente por CPF.
 *
 * Classe pura: nao conhece Bref nem o runtime do Lambda. A amarracao esta' em
 * `handler-auth.php`. Todas as dependencias sao injetadas, o que torna a tabela de
 * erros da secao 5 dos Contratos testavel ramo a ramo.
 */
final class AuthCpfHandler
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly SecretsProviderInterface $secrets,
        private readonly string $authSecretId,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $event Evento HTTP API payload 2.0.
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    public function handle(array $event): array
    {
        try {
            $body = $this->decodeBody($event);

            /** @var mixed $rawCpf */
            $rawCpf = $body['cpf'] ?? null;

            if (!is_string($rawCpf) || trim($rawCpf) === '') {
                return self::error(400, 'O campo cpf é obrigatório.');
            }

            try {
                $cpf = new Cpf($rawCpf);
            } catch (InvalidCpfException) {
                return self::error(400, 'CPF inválido.');
            }

            $customer = $this->customers->findByDocument($cpf->getValue());

            if ($customer === null) {
                return self::error(404, 'Cliente não encontrado.');
            }

            if (!$customer->isActive()) {
                return self::error(403, 'Cliente inativo. Procure a oficina.');
            }

            $secret     = $this->secrets->get($this->authSecretId);
            $jwtSecret  = $secret['JWT_SECRET'] ?? null;
            $expiration = (int)($secret['JWT_EXPIRATION'] ?? 3600);

            if (!is_string($jwtSecret) || $jwtSecret === '') {
                return self::error(500, 'Erro interno.');
            }

            $jwt = new JwtProvider($jwtSecret, $expiration, $this->clock);

            // Ordem das claims especificas conforme secao 4 dos Contratos e o
            // `contract-token.md`: sub, role, cpf, name — depois iss, iat, exp.
            $token = $jwt->generate([
                'sub'  => $customer->id,
                'role' => 'customer',
                'cpf'  => $cpf->getValue(),
                'name' => $customer->name,
            ]);

            return self::json(200, [
                'token'     => $token,
                'expiresIn' => $expiration,
                'customer'  => [
                    'id'   => $customer->id,
                    'name' => $customer->name,
                ],
            ]);
        } catch (Throwable $e) {
            // Log estruturado em stdout; a resposta nunca vaza detalhe interno.
            self::logError($e);

            return self::error(500, 'Erro interno.');
        }
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function decodeBody(array $event): array
    {
        /** @var mixed $raw */
        $raw = $event['body'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        if (($event['isBase64Encoded'] ?? false) === true) {
            $decoded = base64_decode($raw, true);
            $raw     = $decoded === false ? '' : $decoded;
        }

        /** @var mixed $parsed */
        $parsed = json_decode($raw, true);

        if (!is_array($parsed)) {
            return [];
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /** @return array{statusCode: int, headers: array<string, string>, body: string} */
    private static function error(int $status, string $message): array
    {
        return self::json($status, ['error' => $message]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    private static function json(int $status, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'statusCode' => $status,
            'headers'    => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'       => $body === false ? '{"error":"Erro interno."}' : $body,
        ];
    }

    private static function logError(Throwable $e): void
    {
        // The repository wraps PDOException in a RepositoryException with a friendly
        // message. Logging only the top-level message throws away the ONLY thing that
        // tells "table does not exist" apart from "access denied" or "connection
        // refused" — all of which surface identically as "Falha ao consultar o cliente".
        $cause = $e->getPrevious();

        $line = json_encode([
            'level'             => 'error',
            'message'           => 'auth.cpf.failed',
            'service'           => 'oficina-auth-cpf',
            'exception_class'   => $e::class,
            'exception_message' => $e->getMessage(),
            'cause_class'       => $cause?->getMessage() === null ? null : $cause::class,
            'cause_message'     => $cause?->getMessage(),
            'file'              => $e->getFile(),
            'line'              => $e->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        error_log($line === false ? 'auth.cpf.failed' : $line);
    }
}
