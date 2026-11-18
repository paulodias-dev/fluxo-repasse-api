<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Consulta com tenant_id irrevogável no WHERE (inclusive writes).
 * Inserções sempre recebem tenant_id do JWT, nunca do payload.
 */
final class TenantQueryBuilder extends Builder
{
    public function __construct(
        int $tenantId,
        string $table,
        string $qualifier
    ) {
        parent::__construct(DB::connection());
        $this->tenantId = $tenantId;
        parent::from($table);
        parent::where($qualifier.'.tenant_id', '=', $tenantId);
    }

    private readonly int $tenantId;

    private function stamp(array $row): array
    {
        if (array_key_exists('tenant_id', $row) && (int) $row['tenant_id'] !== $this->tenantId) {
            throw new InvalidArgumentException('Empresa do registro inválida.');
        }

        $row['tenant_id'] = $this->tenantId;

        return $row;
    }

    public function insert(array $values)
    {
        if ($values === []) {
            return false;
        }
        $rows = array_is_list($values) && isset($values[0]) && is_array($values[0])
            ? array_map($this->stamp(...), $values)
            : $this->stamp($values);

        return parent::insert($rows);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->stamp($values), $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        if ($values === []) return 0;
        $rows = array_is_list($values) && isset($values[0]) && is_array($values[0])
            ? array_map($this->stamp(...), $values)
            : $this->stamp($values);

        return parent::insertOrIgnore($rows);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($values === []) return 0;
        $rows = array_is_list($values) && isset($values[0]) && is_array($values[0])
            ? array_map($this->stamp(...), $values)
            : $this->stamp($values);
        if (is_array($update) && in_array('tenant_id', $update, true)) {
            throw new InvalidArgumentException('Não é permitido alterar a empresa.');
        }
        return parent::upsert($rows, $uniqueBy, $update);
    }

    public function update(array $values)
    {
        if (array_key_exists('tenant_id', $values)) {
            throw new InvalidArgumentException('Não é permitido alterar a empresa.');
        }

        return parent::update($values);
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        $result = parent::join($table, $first, $operator, $second, $type, $where);

        // A condição do tenant do JOIN deve estar no ON (também LEFT JOIN).
        if (is_string($table)) {
            $alias = TenantDB::qualifierFor($table);
            if ($alias !== null) {
                $last = $this->joins[count($this->joins) - 1];
                $last->where($alias.'.tenant_id', '=', $this->tenantId);
            }
        }

        return $result;
    }
}
