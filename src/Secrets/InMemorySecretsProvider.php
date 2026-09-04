<?php

declare(strict_types=1);

namespace Oficina\Auth\Secrets;

/**
 * Provedor de segredos para testes. Nao usar em runtime.
 */
final class InMemorySecretsProvider implements SecretsProviderInterface
{
    /** @param array<string, array<string, mixed>> $secrets */
    public function __construct(private readonly array $secrets)
    {
    }

    public function get(string $secretId): array
    {
        if (!array_key_exists($secretId, $this->secrets)) {
            throw new SecretsException("Segredo não encontrado: {$secretId}");
        }

        return $this->secrets[$secretId];
    }
}
