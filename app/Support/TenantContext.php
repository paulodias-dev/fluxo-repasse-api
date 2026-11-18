<?php

namespace App\Support;

use Closure;
use RuntimeException;

/**
 * Request-scoped tenant identity. Never read the tenant from a client-supplied
 * header or query string. CLI seeders may temporarily enter a tenant context.
 */
final class TenantContext
{
    private ?int $id = null;

    public function set(int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('Identificador de empresa inválido.');
        }

        $this->id = $id;
    }

    public function current(): ?int
    {
        return $this->id;
    }

    public function id(): int
    {
        return $this->id ?? throw new RuntimeException('Escopo da empresa ausente. Operação bloqueada.');
    }

    public function run(int $id, Closure $callback): mixed
    {
        $before = $this->id;
        $this->set($id);

        try {
            return $callback();
        } finally {
            $this->id = $before;
        }
    }
}
