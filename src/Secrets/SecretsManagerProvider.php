<?php

declare(strict_types=1);

namespace Oficina\Auth\Secrets;

use Aws\SecretsManager\SecretsManagerClient;
use Throwable;

/**
 * Le segredos do AWS Secrets Manager.
 *
 * O cache vive numa propriedade ESTATICA de proposito: o Lambda reaproveita o
 * container de execucao entre invocacoes, entao o segundo request em diante nao
 * paga a chamada de rede nem a cota da API do Secrets Manager (secao 3 dos Contratos).
 * O cache e' por ARN/nome do segredo, e a rotacao do segredo so' e' percebida quando
 * a AWS recicla o container — comportamento aceito e documentado no README.
 */
final class SecretsManagerProvider implements SecretsProviderInterface
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    private ?SecretsManagerClient $client = null;

    public function __construct(
        private readonly string $region = 'us-east-1',
        ?SecretsManagerClient $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * Util em testes e apos rotacao forcada.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    public function get(string $secretId): array
    {
        if (isset(self::$cache[$secretId])) {
            return self::$cache[$secretId];
        }

        try {
            $result = $this->client()->getSecretValue(['SecretId' => $secretId]);
            /** @var mixed $raw */
            $raw = $result['SecretString'] ?? null;
        } catch (Throwable $e) {
            throw new SecretsException("Falha ao ler o segredo {$secretId}.", 0, $e);
        }

        if (!is_string($raw)) {
            throw new SecretsException("Segredo {$secretId} não possui SecretString.");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new SecretsException("Segredo {$secretId} não é um JSON de objeto.");
        }

        /** @var array<string, mixed> $decoded */
        self::$cache[$secretId] = $decoded;

        return $decoded;
    }

    private function client(): SecretsManagerClient
    {
        if ($this->client === null) {
            $this->client = new SecretsManagerClient([
                'region'  => $this->region,
                'version' => '2017-10-17',
            ]);
        }

        return $this->client;
    }
}
