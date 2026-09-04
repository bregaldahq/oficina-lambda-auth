<?php

declare(strict_types=1);

namespace Oficina\Auth\Domain;

use InvalidArgumentException;

/**
 * Lancada quando o CPF informado nao passa na validacao de formato ou de digito
 * verificador. O handler traduz para HTTP 400 com a mensagem da secao 5 dos Contratos.
 */
final class InvalidCpfException extends InvalidArgumentException
{
}
