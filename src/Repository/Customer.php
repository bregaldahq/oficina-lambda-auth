<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

/**
 * Projecao minima da linha de `customers` — exatamente as colunas que a consulta
 * `SELECT id, name, status FROM customers WHERE document = ?` devolve.
 */
final class Customer
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
