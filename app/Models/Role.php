<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    protected $fillable = ['slug', 'name', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            if (TenantContext::active()) {
                $query->where('roles.tenant_id', TenantContext::id());
            } elseif (! app()->runningInConsole()) {
                $query->whereRaw('1 = 0');
            }
        });
        static::creating(function (Role $role): void {
            if (! $role->tenant_id) {
                $role->tenant_id = TenantContext::active() ? TenantContext::id()
                    : (app()->runningInConsole() && DB::table('tenants')->count() === 1
                        ? (int) DB::table('tenants')->value('id') : null);
            }
            if (! $role->tenant_id) {
                throw new \LogicException('Empresa obrigatória ao cadastrar papel.');
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        if (TenantContext::active() && DB::table('tenants')->count() > 1) {
            return $this->belongsToMany(User::class, 'tenant_role_user')
                ->wherePivot('tenant_id', TenantContext::id())
                ->withPivotValue('tenant_id', TenantContext::id());
        }

        return $this->belongsToMany(User::class);
    }
}
