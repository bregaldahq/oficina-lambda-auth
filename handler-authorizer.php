<?php

declare(strict_types=1);

/**
 * Amarracao fina com o Bref para a Lambda `jwt-authorizer`.
 * Fora da VPC: so' Secrets Manager, nada de banco.
 */

use Oficina\Auth\Handler\JwtAuthorizerHandler;
use Oficina\Auth\Secrets\SecretsManagerProvider;

require __DIR__ . '/vendor/autoload.php';

$region       = getenv('AWS_REGION') ?: 'us-east-1';
$authSecretId = getenv('AUTH_SECRET_ID') ?: '';

$handler = new JwtAuthorizerHandler(new SecretsManagerProvider($region), $authSecretId);

return static fn (array $event): array => $handler->handle($event);
