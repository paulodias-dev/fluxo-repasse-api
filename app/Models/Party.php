<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Support\TenantContext;

final class Party extends Model
{
    protected $fillable = ['type', 'name', 'email', 'phone', 'notes', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            if (Auth::guard('api')->check()) {
                $query->where('parties.tenant_id', TenantContext::id());
            } elseif (! app()->runningInConsole()) {
                $query->whereRaw('1 = 0');
            }
        });
        static::creating(static function (self $party): void {
            if (Auth::guard('api')->check()) {
                $party->tenant_id = TenantContext::id();
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
