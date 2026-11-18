<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Party extends Model
{
    protected $fillable = ['type', 'name', 'email', 'phone', 'notes', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('current_tenant', static function (Builder $query): void {
            $query->where('parties.tenant_id', app(TenantContext::class)->id());
        });
        static::creating(static function (Party $party): void {
            $party->tenant_id = app(TenantContext::class)->id();
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
