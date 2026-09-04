<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

use PDO;
use Throwable;

/**
 * Leitura de clientes no MySQL do RDS. A conexao e' preguicosa (uma factory
 * injetada) para que o container do Lambda possa reaproveita-la entre invocacoes
 * e para que abrir conexao nao seja custo de cold start quando o CPF ja' e' invalido.
 */
final class PdoCustomerRepository implements CustomerRepositoryInterface
{
    private ?PDO $pdo = null;

    /** @var callable(): PDO */
    private $factory;

    /** @param callable(): PDO $factory */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function findByDocument(string $document): ?Customer
    {
        try {
            $pdo = $this->pdo ??= ($this->factory)();

            $stmt = $pdo->prepare('SELECT id, name, status FROM customers WHERE document = ?');
            $stmt->execute([$document]);

            /** @var array{id: string, name: string, status: string}|false $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new RepositoryException('Falha ao consultar o cliente.', 0, $e);
        }

        if ($row === false) {
            return null;
        }

        return new Customer((string)$row['id'], (string)$row['name'], (string)$row['status']);
    }
}
