<?php

declare(strict_types=1);

namespace Oficina\Auth\Domain;

/**
 * CPF do cliente.
 *
 * O algoritmo de digito verificador e' uma replica exata do
 * `App\Domain\ValueObject\Document::isValidCpf()` da aplicacao, incluindo a rejeicao
 * de sequencias de digitos repetidos (000.000.000-00, 111.111.111-11, ...).
 *
 * Diferenca deliberada em relacao ao VO da aplicacao: aqui SO CPF (11 digitos) e'
 * aceito. CNPJ (14 digitos) e' invalido nesta rota — secao 5 dos Contratos.
 */
final class Cpf
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $digits = (string)preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 11) {
            throw new InvalidCpfException("CPF inválido: {$value}");
        }

        if (!self::isValidCpf($digits)) {
            throw new InvalidCpfException("CPF inválido: {$value}");
        }

        $this->value = $digits;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function isValidCpf(string $digits): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$digits[$i] * (10 - $i);
        }
        $remainder  = $sum % 11;
        $firstCheck = $remainder < 2 ? 0 : 11 - $remainder;

        if ((int)$digits[9] !== $firstCheck) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int)$digits[$i] * (11 - $i);
        }
        $remainder   = $sum % 11;
        $secondCheck = $remainder < 2 ? 0 : 11 - $remainder;

        return (int)$digits[10] === $secondCheck;
    }
}
