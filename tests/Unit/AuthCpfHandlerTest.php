<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Handler\AuthCpfHandler;
use Oficina\Auth\Repository\Customer;
use Oficina\Auth\Secrets\InMemorySecretsProvider;
use Oficina\Auth\Secrets\SecretsProviderInterface;
use Oficina\Auth\Security\JwtProvider;
use Oficina\Auth\Tests\Support\FakeCustomerRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cobre ramo a ramo a tabela de codigos de erro da secao 5 dos Contratos.
 */
final class AuthCpfHandlerTest extends TestCase
{
    private const SECRET_ID = 'oficina/test/auth';
    private const CPF       = '52998224725';
    private const NOW       = 1767225600;

    private function handler(FakeCustomerRepository $repository, ?SecretsProviderInterface $secrets = null): AuthCpfHandler
    {
        return new AuthCpfHandler(
            $repository,
            $secrets ?? new InMemorySecretsProvider([
                self::SECRET_ID => ['JWT_SECRET' => 'segredo-de-teste', 'JWT_EXPIRATION' => '3600'],
            ]),
            self::SECRET_ID,
            static fn (): int => self::NOW,
        );
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function body(array $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$response['body'], true);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function event(mixed $body): array
    {
        return ['body' => is_string($body) ? $body : json_encode($body)];
    }

    public function testReturns200ForActiveCustomer(): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', 'ACTIVE'));

        $response = $this->handler($repository)->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(200, $response['statusCode']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);

        $body = $this->body($response);
        self::assertSame(3600, $body['expiresIn']);
        self::assertSame(['id' => 'cust-1', 'name' => 'Maria Souza'], $body['customer']);

        $claims = (new JwtProvider('segredo-de-teste', 3600, static fn (): int => self::NOW))
            ->validate((string)$body['token']);

        self::assertSame('cust-1', $claims['sub']);
        self::assertSame('customer', $claims['role']);
        self::assertSame(self::CPF, $claims['cpf']);
        self::assertSame('Maria Souza', $claims['name']);
        self::assertSame(self::NOW + 3600, $claims['exp']);
    }

    public function testStripsMaskBeforeQueryingTheDatabase(): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', 'ACTIVE'));

        $response = $this->handler($repository)->handle($this->event(['cpf' => '529.982.247-25']));

        self::assertSame(200, $response['statusCode']);
        self::assertSame(self::CPF, $repository->lastDocument);
    }

    public function testAcceptsBase64EncodedBody(): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', 'ACTIVE'));

        $response = $this->handler($repository)->handle([
            'body'            => base64_encode((string)json_encode(['cpf' => self::CPF])),
            'isBase64Encoded' => true,
        ]);

        self::assertSame(200, $response['statusCode']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingCpfProvider(): iterable
    {
        yield 'sem body'          => [[]];
        yield 'body vazio'        => [['body' => '']];
        yield 'json invalido'     => [['body' => 'nao-e-json']];
        yield 'sem a chave cpf'   => [['body' => '{"documento":"52998224725"}']];
        yield 'cpf vazio'         => [['body' => '{"cpf":""}']];
        yield 'cpf so espacos'    => [['body' => '{"cpf":"   "}']];
        yield 'cpf nulo'          => [['body' => '{"cpf":null}']];
        yield 'cpf numerico'      => [['body' => '{"cpf":52998224725}']];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @dataProvider missingCpfProvider
     */
    public function testReturns400WhenCpfIsMissingOrEmpty(array $event): void
    {
        $response = $this->handler(new FakeCustomerRepository())->handle($event);

        self::assertSame(400, $response['statusCode']);
        self::assertSame(['error' => 'O campo cpf é obrigatório.'], $this->body($response));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCpfProvider(): iterable
    {
        yield 'digito verificador errado' => ['52998224724'];
        yield 'sequencia repetida'        => ['11111111111'];
        yield 'curto demais'              => ['123'];
        yield 'cnpj'                      => ['11222333000181'];
        yield 'cnpj com mascara'          => ['11.222.333/0001-81'];
    }

    /** @dataProvider invalidCpfProvider */
    public function testReturns400ForInvalidCpf(string $cpf): void
    {
        $response = $this->handler(new FakeCustomerRepository())->handle($this->event(['cpf' => $cpf]));

        self::assertSame(400, $response['statusCode']);
        self::assertSame(['error' => 'CPF inválido.'], $this->body($response));
    }

    public function testReturns404WhenCustomerIsNotRegistered(): void
    {
        $response = $this->handler(new FakeCustomerRepository())->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(404, $response['statusCode']);
        self::assertSame(['error' => 'Cliente não encontrado.'], $this->body($response));
    }

    /** @return iterable<string, array{string}> */
    public static function blockedStatusProvider(): iterable
    {
        yield 'inativo'   => ['INACTIVE'];
        yield 'bloqueado' => ['BLOCKED'];
    }

    /** @dataProvider blockedStatusProvider */
    public function testReturns403ForNonActiveCustomer(string $status): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', $status));

        $response = $this->handler($repository)->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(403, $response['statusCode']);
        self::assertSame(['error' => 'Cliente inativo. Procure a oficina.'], $this->body($response));
    }

    public function testReturns500WhenTheDatabaseFails(): void
    {
        $response = $this->handler((new FakeCustomerRepository())->failing())
            ->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(500, $response['statusCode']);
        self::assertSame(['error' => 'Erro interno.'], $this->body($response));
    }

    public function testReturns500WhenTheSecretIsUnreadable(): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', 'ACTIVE'));

        $broken = new class () implements SecretsProviderInterface {
            public function get(string $secretId): array
            {
                throw new RuntimeException('sem acesso ao Secrets Manager');
            }
        };

        $response = $this->handler($repository, $broken)->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(500, $response['statusCode']);
        self::assertSame(['error' => 'Erro interno.'], $this->body($response));
    }

    public function testReturns500WhenJwtSecretIsMissingFromTheSecret(): void
    {
        $repository = (new FakeCustomerRepository())
            ->with(self::CPF, new Customer('cust-1', 'Maria Souza', 'ACTIVE'));

        $response = $this->handler($repository, new InMemorySecretsProvider([self::SECRET_ID => []]))
            ->handle($this->event(['cpf' => self::CPF]));

        self::assertSame(500, $response['statusCode']);
        self::assertSame(['error' => 'Erro interno.'], $this->body($response));
    }

    /**
     * Um CPF invalido nao pode chegar ao banco — validar antes economiza conexao e
     * evita expor a consulta a entrada arbitraria.
     */
    public function testDoesNotTouchTheDatabaseWhenCpfIsInvalid(): void
    {
        $repository = (new FakeCustomerRepository())->failing();

        $response = $this->handler($repository)->handle($this->event(['cpf' => '00000000000']));

        self::assertSame(400, $response['statusCode']);
        self::assertNull($repository->lastDocument);
    }
}
