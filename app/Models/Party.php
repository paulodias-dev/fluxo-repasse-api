<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Party extends Model
{
    protected $fillable = ['type', 'name', 'email', 'phone', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
