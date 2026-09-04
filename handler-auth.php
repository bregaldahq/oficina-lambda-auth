<?php

declare(strict_types=1);

/**
 * Amarracao fina com o Bref para a Lambda `auth-cpf`.
 *
 * Toda a regra vive em `Oficina\Auth\Handler\AuthCpfHandler`, que e' uma classe pura
 * e testavel sem o Bref instalado. Este arquivo so' monta as dependencias reais
 * (Secrets Manager, PDO/RDS) e delega.
 */

use Oficina\Auth\Handler\AuthCpfHandler;
use Oficina\Auth\Repository\PdoCustomerRepository;
use Oficina\Auth\Secrets\SecretsManagerProvider;

require __DIR__ . '/vendor/autoload.php';

$region       = getenv('AWS_REGION') ?: 'us-east-1';
$authSecretId = getenv('AUTH_SECRET_ID') ?: '';
$dbSecretId   = getenv('DB_SECRET_ID') ?: '';

$secrets = new SecretsManagerProvider($region);

$repository = new PdoCustomerRepository(static function () use ($secrets, $dbSecretId): PDO {
    $db = $secrets->get($dbSecretId);

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)($db['host'] ?? ''),
        (int)($db['port'] ?? 3306),
        (string)($db['dbname'] ?? '')
    );

    return new PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ]);
});

$handler = new AuthCpfHandler($repository, $secrets, $authSecretId);

return static fn (array $event): array => $handler->handle($event);
