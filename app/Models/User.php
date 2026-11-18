<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
        ];
    }

    public function roles(): BelongsToMany
    {
        if (TenantContext::active() && DB::table('tenants')->count() > 1) {
            return $this->belongsToMany(Role::class, 'tenant_role_user')
                ->wherePivot('tenant_id', TenantContext::id())
                ->withPivotValue('tenant_id', TenantContext::id());
        }

        return $this->belongsToMany(Role::class);
    }

    public function belongsToLegacyPlatformAdmin(): bool
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->where('slug', 'platform_admin')->exists();
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (! Schema::hasTable('tenant_users')) {
                return;
            }
            // Novos usuários herdam APENAS o tenant corrente; na instalação
            // de empresa única, preserva os seeders/factories antigos.
            $id = TenantContext::active() ? TenantContext::id()
                : (DB::table('tenants')->count() === 1
                    ? (int) DB::table('tenants')->value('id') : null);
            if ($id) {
                DB::table('tenant_users')->updateOrInsert(
                    ['tenant_id' => $id, 'user_id' => $user->id],
                    ['membership_role' => 'member', 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now()]
                );
            }
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
        return ['av' => (int) $this->auth_version];
    }
}
