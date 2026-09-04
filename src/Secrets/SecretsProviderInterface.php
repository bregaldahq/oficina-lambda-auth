<?php

declare(strict_types=1);

namespace Oficina\Auth\Secrets;

/**
 * Abstracao do acesso ao Secrets Manager. Existe para que os testes dos handlers
 * possam injetar um provedor em memoria, sem SDK e sem credencial AWS.
 */
interface SecretsProviderInterface
{
    /**
     * Devolve o segredo decodificado como array associativo.
     *
     * @return array<string, mixed>
     *
     * @throws SecretsException quando o segredo nao existe ou nao e' JSON valido.
     */
    public function get(string $secretId): array;
}
