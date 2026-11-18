<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TenantDatabase
{
    public static function isBusinessTable(string $name): bool
    {
        return (bool) preg_match('/^(?:parties|party_events|party_links|repasse_[a-z_]+)$/D', $name);
    }

    /**
     * All business data must go through a tenant-aware query builder.
     * Global metadata tables (roles, permissions, migrations, auth events)
     * keep Laravel's standard query builder.
     */
    public static function table(string $table): Builder
    {
        if (! preg_match('/^([a-z_]+)(?:\s+(?:as\s+)?([a-z_]+))?$/iD', $table, $matches)) {
            throw new InvalidArgumentException('Expressão de tabela não permitida sem escopo explícito.');
        }

        if (! self::isBusinessTable($matches[1])) {
            return DB::table($table);
        }

        $id = app(TenantContext::class)->id();
        $alias = $matches[2] ?? $matches[1];
        $query = new TenantQueryBuilder(DB::connection(), tenantId: $id);
        $query->from($table);

        return $query->where($alias.'.tenant_id', $id);
    }
}
