<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Escopo também protege route-model-binding e Party::query(). */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $id = TenantContext::current();
            if ($id !== null) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $id);
            } elseif (TenantContext::guardedApiRequest()) {
                // Eloquent User auth/login é exceção controlada por middleware.
                if (! ($builder->getModel() instanceof \App\Models\User)) {
                    $builder->whereRaw('1 = 0');
                }
            }
        });

        static::creating(function (Model $model): void {
            $id = TenantContext::current();
            if ($id !== null) {
                if ($model->tenant_id !== null && (int) $model->tenant_id !== $id) {
                    throw ValidationException::withMessages(['tenant_id' => 'Empresa inválida.']);
                }
                $model->tenant_id = $id;
            }
        });
    }
}
