<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tenant vem SOMENTE da sessão JWT validada, nunca de query/body/header.
 * Uma request autenticada sem tenant conhecido não pode consultar registros.
 */
final class TenantContext
{
    public static function current(): ?int
    {
        $id = request()->attributes->get('verified_tenant_id');

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function requireId(): int
    {
        $id = self::current();
        if (! $id) {
            throw new HttpException(403, 'Empresa não selecionada ou sessão inválida.');
        }

        return $id;
    }

    public static function guardedApiRequest(): bool
    {
        return request()->is('api/*');
    }
}
