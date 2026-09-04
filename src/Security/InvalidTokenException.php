<?php

declare(strict_types=1);

namespace Oficina\Auth\Security;

use RuntimeException;

/**
 * Espelha a `App\Domain\Exception\DomainException` lancada pelo `JwtProvider` da
 * aplicacao quando o token e' malformado, tem assinatura incorreta ou esta' expirado.
 */
final class InvalidTokenException extends RuntimeException
{
}
