<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, BelongsToTenant;

    protected $attributes = [
        'is_active' => true,
        'auth_version' => 1,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_name',
        'preferences',
        'avatar_path',
        'password',
        'tenant_id',
        'email_verified_at',
        'is_active',
        'auth_version',
        'terms_accepted_at',
        'terms_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'auth_version',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'auth_version' => 'integer',
            'preferences' => 'array',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        $tenantId = \App\Support\TenantContext::current() ?? $this->tenant_id;
        $roles = $this->belongsToMany(Role::class);
        // Relações geradas por whereHas() no CLI não têm tenant_id do modelo:
        // nesse caso quem chama deve escopar a query de users pelo tenant.
        if ($tenantId === null) {
            return $roles;
        }

        return $roles->where(function ($query) use ($tenantId): void {
            $query->where('roles.tenant_id', $tenantId)
                ->orWhere(function ($global): void {
                    $global->whereNull('roles.tenant_id')
                        ->where('roles.slug', 'platform_admin');
                });
        });
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()->whereHas('permissions', function ($query) use ($permission): void {
            $query->where('key', $permission);
        })->exists();
    }

    /** @return list<string> */
    public function grantedPermissions(): array
    {
        return $this->roles()->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->unique()->values()->all();
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /** @return array<string, mixed> */
    public function getJWTCustomClaims(): array
    {
        return ['av' => (int) $this->auth_version, 'tid' => (int) $this->tenant_id];
    }
}
