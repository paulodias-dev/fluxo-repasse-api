<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Validation\ValidationException;

/**
 * Query Builder tenant-aware para consultas legadas de DB::table.
 * Escopa tabela base, joins de domínio e injeta tenant_id em inserts.
 */
final class TenantQueryBuilder extends Builder
{
    private int $tenant;
    private string $rootAlias;

    public function forTenant(int $tenant, string $alias): static
    {
        $this->tenant = $tenant;
        $this->rootAlias = $alias;

        return $this;
    }

    /** @return array<string, mixed> */
    private function tenantRow(array $row): array
    {
        if (array_key_exists('tenant_id', $row) && (int) $row['tenant_id'] !== $this->tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'Empresa inválida.']);
        }
        $row['tenant_id'] = $this->tenant;

        return $row;
    }

    public function insert(array $values)
    {
        if ($values === []) return true;
        $rows = array_is_list($values) ? $values : [$values];
        $rows = array_map(fn (array $row) => $this->tenantRow($row), $rows);

        return parent::insert(count($rows) === 1 && ! array_is_list($values) ? $rows[0] : $rows);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->tenantRow($values), $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        $rows = array_is_list($values) ? $values : [$values];

        return parent::insertOrIgnore(array_map(fn (array $row) => $this->tenantRow($row), $rows));
    }

    public function update(array $values)
    {
        if (array_key_exists('tenant_id', $values)) {
            throw ValidationException::withMessages(['tenant_id' => 'Não é permitido mudar a empresa.']);
        }

        return parent::update($values);
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        $result = parent::join($table, $first, $operator, $second, $type, $where);
        $base = is_string($table)
            ? trim(preg_split('/\s+as\s+/i', $table)[0]) : '';
        if (is_string($table) && TenantTable::owned($base) && $this->joins !== null) {
            $alias = trim(preg_split('/\s+as\s+/i', $table)[1] ?? $base);
            $last = $this->joins[array_key_last($this->joins)];
            $last->on($alias.'.tenant_id', '=', $this->rootAlias.'.tenant_id');
        }

        return $result;
    }
}
