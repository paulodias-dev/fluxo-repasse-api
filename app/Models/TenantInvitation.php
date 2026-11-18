<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantInvitation extends Model
{
    protected $fillable = [
        'tenant_id',
        'invited_by',
        'role_id',
        'email',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
