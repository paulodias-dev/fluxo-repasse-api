<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Support\TenantContext;

final class Role extends Model
{
    protected $fillable = ['slug', 'name', 'tenant_id'];

    protected static function booted(): void
    {
        static::creating(static function (self $role): void {
            if ($role->slug !== 'platform_admin' && Auth::guard('api')->check()) {
                $role->tenant_id = TenantContext::id();
            } elseif ($role->slug !== 'platform_admin' && app()->environment('testing')
                && ! $role->tenant_id) {
                $role->tenant_id = 1;
            }
        });
        static::addGlobalScope('tenant', static function (Builder $query): void {
            if (Auth::guard('api')->check()) {
                $query->where('roles.tenant_id', TenantContext::id());
            } elseif (! app()->runningInConsole()) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivotValue('tenant_id', $this->tenant_id);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivotValue('tenant_id', $this->tenant_id);
    }
}
