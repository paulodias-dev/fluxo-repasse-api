<?php

namespace App\Support;

use Illuminate\Http\Request;
use LogicException;

final class TenantContext
{
    public static function id(): int
    {
        $tenant = request()->attributes->get('current_tenant_id');
        if (! is_int($tenant) || $tenant < 1) {
            throw new LogicException('Tenant obrigatório para acessar dados empresariais.');
        }

        return $tenant;
    }

    public static function active(): bool
    {
        return is_int(request()->attributes->get('current_tenant_id'));
    }

    public static function set(Request $request, int $tenant): void
    {
        $request->attributes->set('current_tenant_id', $tenant);
    }
}
