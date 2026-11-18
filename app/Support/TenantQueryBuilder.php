<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use InvalidArgumentException;
use LogicException;

/**
 * Consulta com tenant_id irrevogável no WHERE (inclusive writes).
 * Construtor compatível com Builder::newQuery()/forNestedWhere().
 */
final class TenantQueryBuilder extends Builder
{
    private ?int $tenantId = null;

    public function __construct(ConnectionInterface $connection, ?Grammar $grammar = null, ?Processor $processor = null)
    {
        parent::__construct($connection, $grammar, $processor);
    }

    public function scopeTo(int $tenantId, string $table, string $qualifier): static
    {
        if ($this->tenantId !== null) throw new LogicException('Escopo já definido.');
        $this->tenantId = $tenantId;
        parent::from($table);
        parent::where($qualifier.'.tenant_id', '=', $tenantId);

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        // OR diretamente na query principal poderia produzir
        // tenant_id = ? AND condicao OR outra_condicao e vazar dados.
        // OR em subquery agrupada where(fn ($q) => ...) continua permitido,
        // pois newQuery() possui tenantId=null (construtor padrão Laravel).
        if ($this->tenantId !== null) {
            throw new LogicException('Agrupe condições OR com where(function ($query) { ... }).');
        }

        return parent::orWhere($column, $operator, $value);
    }

    private function stamp(array $row): array
    {
        if ($this->tenantId === null) {
            throw new LogicException('Escrita sem tenant verificado.');
        }
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
            if ($alias !== null && $this->tenantId !== null) {
                $last = $this->joins[count($this->joins) - 1];
                $last->where($alias.'.tenant_id', '=', $this->tenantId);
                // parent::join já copiou os bindings. A nova condição ON
                // precisa ser adicionada também aos bindings do builder pai.
                $this->addBinding([$this->tenantId], 'join');
            }
        }

        return $result;
    }
}
