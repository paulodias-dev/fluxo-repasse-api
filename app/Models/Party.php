<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

final class Party extends Model
{
    use BelongsToTenant;

    protected $fillable = ['type', 'name', 'email', 'phone', 'notes', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
