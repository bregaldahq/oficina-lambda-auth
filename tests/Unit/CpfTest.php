<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Domain\Cpf;
use Oficina\Auth\Domain\InvalidCpfException;
use PHPUnit\Framework\TestCase;

final class CpfTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validCpfProvider(): iterable
    {
        yield 'sem mascara'   => ['52998224725', '52998224725'];
        yield 'com mascara'   => ['529.982.247-25', '52998224725'];
        yield 'com espacos'   => [' 529 982 247 25 ', '52998224725'];
        yield 'digito zero'   => ['11144477735', '11144477735'];
    }

    /** @dataProvider validCpfProvider */
    public function testAcceptsValidCpf(string $input, string $expected): void
    {
        self::assertSame($expected, (new Cpf($input))->getValue());
        self::assertSame($expected, (string)new Cpf($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCpfProvider(): iterable
    {
        yield 'digito verificador errado'   => ['52998224724'];
        yield 'segundo digito errado'       => ['52998224715'];
        yield 'sequencia repetida zeros'    => ['00000000000'];
        yield 'sequencia repetida uns'      => ['11111111111'];
        yield 'sequencia repetida noves'    => ['99999999999'];
        yield 'curto demais'                => ['1234567890'];
        yield 'longo demais'                => ['123456789012'];
        yield 'vazio'                       => [''];
        yield 'somente letras'              => ['abcdefghijk'];
        yield 'cnpj valido e' . ' rejeitado' => ['11222333000181'];
    }

    /** @dataProvider invalidCpfProvider */
    public function testRejectsInvalidCpf(string $input): void
    {
        $this->expectException(InvalidCpfException::class);

        new Cpf($input);
    }

    /**
     * A rota `POST /auth/cpf` aceita SOMENTE CPF. CNPJ — mesmo com digitos
     * verificadores validos — vira 400 (secao 5 dos Contratos).
     */
    public function testCnpjIsNotAcceptedEvenWhenValid(): void
    {
        $this->expectException(InvalidCpfException::class);

        new Cpf('11.222.333/0001-81');
    }
}
