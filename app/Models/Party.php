<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

final class Party extends Model
{
    protected $fillable = ['type', 'name', 'email', 'phone', 'notes'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            if (TenantContext::active()) {
                $query->where('parties.tenant_id', TenantContext::id());
            } elseif (! app()->runningInConsole()) {
                $query->whereRaw('1 = 0');
            }
        });

        static::creating(function (Party $party): void {
            $party->tenant_id = TenantContext::active() ? TenantContext::id()
                : (app()->runningInConsole() && DB::table('tenants')->count() === 1
                    ? (int) DB::table('tenants')->value('id') : null);
            if (! $party->tenant_id) {
                throw new \LogicException('Empresa obrigatória no cadastro de participante.');
            }
        });
    }



    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
