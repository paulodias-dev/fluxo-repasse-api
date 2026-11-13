<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminUserAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('jwt.secret', str_repeat('audit-test-only-', 4));
        config()->set('jwt.blacklist_enabled', true);
        config()->set('jwt.persistent_claims', ['av']);
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $permission = Permission::query()->firstOrCreate(['key' => 'users.manage']);
        $role = Role::query()->firstOrCreate(
            ['slug' => 'platform_admin'], ['name' => 'Administrador técnico']
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function event(User $user, string $type, ?User $actor = null): void
    {
        DB::table('auth_security_events')->insert([
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $type,
            'ip_hash' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_requires_authentication_and_users_manage(): void
    {
        $target = User::factory()->create();
        $ordinary = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $this->getJson('/api/v1/admin/users/'.$target->id.'/events')->assertUnauthorized();
        $this->withToken($this->tokenFor($ordinary))
            ->getJson('/api/v1/admin/users/'.$target->id.'/events')
            ->assertForbidden();
    }

    public function test_audit_returns_only_events_for_selected_user_without_ip_hash(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $other = User::factory()->create();
        $this->event($target, 'account_updated', $admin);
        $this->event($target, 'account_email_changed', $admin);
        $this->event($other, 'account_deactivated', $admin);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users/'.$target->id.'/events')
            ->assertOk()
            ->assertJsonPath('user_id', $target->id)
            ->assertJsonCount(2, 'events.data')
            ->assertJsonPath('events.data.0.event_type', 'account_email_changed')
            ->assertJsonMissingPath('events.data.0.ip_hash')
            ->assertJsonMissingPath('events.data.0.password')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_filter_by_type_and_date_range(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->event($target, 'account_updated', $admin);
        $this->event($target, 'account_email_changed', $admin);
        $day = now()->format('Y-m-d');

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users/'.$target->id.'/events?type=account_updated&from='.$day.'&to='.$day)
            ->assertOk()->assertJsonCount(1, 'events.data')
            ->assertJsonPath('events.data.0.event_type', 'account_updated');
    }

    public function test_rejects_invalid_filters(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->getJson('/api/v1/admin/users/'.$target->id.'/events?type=not_a_valid_event')
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/users/'.$target->id.'/events?from=2026-11-12&to=2026-11-11')
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/users/'.$target->id.'/events?page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['page']);
    }

    public function test_unknown_user_returns_not_found(): void
    {
        $admin = $this->admin();
        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users/99999999/events')
            ->assertNotFound();
    }
}
