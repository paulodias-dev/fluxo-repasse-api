<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/** Tenant da sessão JWT, nunca de parâmetro, header ou body fornecido. */
final class TenantContext
{
    public static function id(): int
    {
        $user = Auth::guard('api')->user();
        if (! $user || ! $user->tenant_id) {
            abort(403, 'Empresa ativa obrigatória.');
        }

        return (int) $user->tenant_id;
    }
}
