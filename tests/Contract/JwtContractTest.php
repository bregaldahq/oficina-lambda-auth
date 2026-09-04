<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Contract;

use Oficina\Auth\Handler\AuthCpfHandler;
use Oficina\Auth\Repository\Customer;
use Oficina\Auth\Repository\CustomerRepositoryInterface;
use Oficina\Auth\Secrets\InMemorySecretsProvider;
use Oficina\Auth\Security\JwtProvider;
use PHPUnit\Framework\TestCase;

/**
 * ESTE E' O TESTE MAIS IMPORTANTE DO REPOSITORIO.
 *
 * Por que:
 *  - Quem EMITE o token e' esta Lambda. Quem o CONSOME e' a aplicacao PHP no EKS, que
 *    revalida a assinatura localmente (defesa em profundidade, secao 5 dos Contratos).
 *  - Os dois lados sao repositorios separados, com deploys independentes. Nao existe
 *    tipo compartilhado, pacote comum ou build conjunto que force os dois a concordarem.
 *  - Basta uma diferenca de um byte na montagem — a ordem das claims no JSON, o `iss`,
 *    o padding do base64url, um espaco no `json_encode` — para a assinatura mudar e
 *    TODO cliente autenticado por CPF passar a tomar 401 em producao. E o erro so'
 *    apareceria em runtime, depois do deploy, com o usuario na frente.
 *
 * A trava: o token literal abaixo foi gerado uma unica vez na integracao e esta'
 * publicado em `docs/fase-3/contract-token.md`. Este repositorio e o da aplicacao tem,
 * cada um, um teste que reproduz EXATAMENTE esta string a partir do mesmo segredo, do
 * mesmo payload e do mesmo `iat`. Se um lado alterar a montagem do JWT, o SEU teste de
 * contrato fica vermelho no CI antes do merge — e o outro lado nem chega a ser afetado.
 *
 * Se este teste quebrar, a resposta certa quase nunca e' atualizar a string esperada.
 * E' descobrir o que mudou no `JwtProvider` e reverter. Alterar o token literal so' e'
 * legitimo com o mesmo commit alterando os dois repositorios e o `contract-token.md`.
 */
final class JwtContractTest extends TestCase
{
    /** Segredo de teste. Nunca usar em ambiente real — o nome diz isso de proposito. */
    private const CONTRACT_SECRET = 'contract-test-secret-do-not-use-in-prod';

    private const CONTRACT_IAT        = 1767225600;
    private const CONTRACT_EXPIRATION = 3600;

    /** Token literal de `docs/fase-3/contract-token.md`. NAO EDITAR sem alterar os dois lados. */
    private const EXPECTED_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
        . '.eyJzdWIiOiIxMTExMTExMS0xMTExLTQxMTEtODExMS0xMTExMTExMTExMTEiLCJyb2xlIjoiY3VzdG9tZXIiLCJjcGYiOiI1Mjk5ODIyNDcyNSIsIm5hbWUiOiJNYXJpYSBTb3V6YSIsImlzcyI6Im9maWNpbmEtbWVjYW5pY2EtYXBpIiwiaWF0IjoxNzY3MjI1NjAwLCJleHAiOjE3NjcyMjkyMDB9'
        . '.vhZR6_6jD_NdgJvg9yCm4vAkeMUXNCAyY8oE7pr9Vws';

    public function testGeneratesTheExactContractToken(): void
    {
        $jwt = new JwtProvider(
            self::CONTRACT_SECRET,
            self::CONTRACT_EXPIRATION,
            static fn (): int => self::CONTRACT_IAT,
        );

        // A ordem deste array E' parte do contrato: sub, role, cpf, name.
        $token = $jwt->generate([
            'sub'  => '11111111-1111-4111-8111-111111111111',
            'role' => 'customer',
            'cpf'  => '52998224725',
            'name' => 'Maria Souza',
        ]);

        self::assertSame(
            self::EXPECTED_TOKEN,
            $token,
            'A montagem do JWT divergiu do contrato compartilhado com a aplicacao. '
            . 'Ver docs/fase-3/CONTRATOS.md secao 4 antes de alterar qualquer coisa.'
        );
    }

    /**
     * O handler real precisa produzir o mesmo token — nao basta o `JwtProvider` estar
     * certo se o handler montar as claims em outra ordem.
     */
    public function testHandlerProducesTheExactContractToken(): void
    {
        $repository = new class () implements CustomerRepositoryInterface {
            public function findByDocument(string $document): ?Customer
            {
                return new Customer('11111111-1111-4111-8111-111111111111', 'Maria Souza', 'ACTIVE');
            }
        };

        $handler = new AuthCpfHandler(
            $repository,
            new InMemorySecretsProvider([
                'oficina/test/auth' => [
                    'JWT_SECRET'     => self::CONTRACT_SECRET,
                    'JWT_EXPIRATION' => (string)self::CONTRACT_EXPIRATION,
                ],
            ]),
            'oficina/test/auth',
            static fn (): int => self::CONTRACT_IAT,
        );

        $response = $handler->handle(['body' => json_encode(['cpf' => '52998224725'])]);

        self::assertSame(200, $response['statusCode']);

        /** @var array{token: string, expiresIn: int} $body */
        $body = json_decode($response['body'], true);

        self::assertSame(self::EXPECTED_TOKEN, $body['token']);
        self::assertSame(self::CONTRACT_EXPIRATION, $body['expiresIn']);
    }

    public function testDecodedClaimsMatchTheDocumentedContract(): void
    {
        [, $payload] = explode('.', self::EXPECTED_TOKEN);

        $json = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4));

        self::assertSame(
            '{"sub":"11111111-1111-4111-8111-111111111111","role":"customer","cpf":"52998224725",'
            . '"name":"Maria Souza","iss":"oficina-mecanica-api","iat":1767225600,"exp":1767229200}',
            $json
        );
    }
}
