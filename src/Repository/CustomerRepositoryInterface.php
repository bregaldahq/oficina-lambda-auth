<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

interface CustomerRepositoryInterface
{
    /**
     * @throws RepositoryException em qualquer falha de infraestrutura (conexao, query).
     */
    public function findByDocument(string $document): ?Customer;
}
