<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;

final class TenantQueryBuilder extends Builder
{
    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        private readonly int $tenantId = 0,
    ) {
        parent::__construct($connection, $grammar, $processor);
    }

    /**
     * Ensure every write carries the server-derived tenant_id. Never trust
     * tenant_id submitted in a request, a service array or a batch payload.
     */
    private function applyTenant(array $values): array
    {
        if ($values === []) {
            return $values;
        }

        if (array_is_list($values) && is_array($values[0] ?? null)) {
            return array_map(fn (array $row): array => $this->applyTenant($row), $values);
        }

        $values['tenant_id'] = $this->tenantId;

        return $values;
    }

    public function insert(array $values)
    {
        return parent::insert($this->applyTenant($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->applyTenant($values), $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        return parent::insertOrIgnore($this->applyTenant($values));
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return parent::upsert($this->applyTenant($values), $uniqueBy, $update);
    }

    /**
     * Tenant restrictions on JOIN ... ON avoid leaking rows through a left
     * join and prevent IDs from other tenants from being projected.
     */
    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        if (is_string($table) &&
            preg_match('/^([a-z_]+)(?:\s+(?:as\s+)?([a-z_]+))?$/iD', $table, $m) &&
            (TenantDatabase::isBusinessTable($m[1]) || $m[1] === 'users')) {
            $alias = $m[2] ?? $m[1];
            $firstCondition = $first;
            $tenantId = $this->tenantId;

            return parent::join($table, static function (JoinClause $join) use (
                $firstCondition, $operator, $second, $where, $alias, $tenantId,
            ): void {
                if ($firstCondition instanceof Closure) {
                    $firstCondition($join);
                } elseif ($where) {
                    $join->where($firstCondition, $operator, $second);
                } else {
                    $join->on($firstCondition, $operator, $second);
                }

                $join->where($alias.'.tenant_id', '=', $tenantId);
            }, null, null, $type);
        }

        return parent::join($table, $first, $operator, $second, $type, $where);
    }
}
