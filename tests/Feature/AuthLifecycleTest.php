<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('jwt.secret', str_repeat('integration-test-only-', 3));
        config()->set('jwt.blacklist_enabled', true);
        config()->set('jwt.persistent_claims', ['av']);
    }

    private function login(User $user, string $password = 'Strong-Passphrase-123!'): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('access_token');
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/password', [])->assertUnauthorized();
    }

    public function test_wrong_current_password_does_not_update_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->login($user);

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'wrong-current',
            'new_password' => 'Another-Secure-Password-234!',
            'new_password_confirmation' => 'Another-Secure-Password-234!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseMissing('auth_security_events', ['event_type' => 'password_changed']);
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->login($user);

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'Strong-Passphrase-123!',
            'new_password' => 'weak',
            'new_password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_password']);
    }

    public function test_changing_password_revokes_all_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $first = $this->login($user);
        $second = $this->login($user);

        $this->withToken($first)->postJson('/api/v1/auth/password', [
            'current_password' => 'Strong-Passphrase-123!',
            'new_password' => 'Another-Secure-Password-234!',
            'new_password_confirmation' => 'Another-Secure-Password-234!',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store');

        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertUnauthorized();

        $newToken = $this->login($user, 'Another-Secure-Password-234!');
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $user->id,
            'event_type' => 'password_changed',
        ]);
        $this->assertSame(2, $user->fresh()->auth_version);
    }

    public function test_inactive_account_cannot_login_or_reuse_existing_token(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->login($user);

        $user->forceFill(['is_active' => false, 'auth_version' => 2])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertUnauthorized()->assertJsonPath('message', 'Credenciais inválidas.');
    }

    public function test_cli_deactivation_revokes_tokens_and_records_event(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->login($user);

        $this->artisan('repasse:user:status', [
            'email' => $user->email,
            'state' => 'inativo',
            '--yes' => true,
        ])->assertExitCode(0);

        $this->assertFalse($user->fresh()->is_active);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $user->id,
            'event_type' => 'account_deactivated',
        ]);
    }

    public function test_refresh_preserves_version_claim(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->login($user);

        $refreshed = $this->withToken($token)->postJson('/api/v1/auth/refresh')
            ->assertOk()->json('access_token');

        $this->withToken($refreshed)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_audit_records_do_not_store_raw_ip_or_login_payload(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();

        $event = DB::table('auth_security_events')->where('event_type', 'login_failed')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->user_id);
        $this->assertSame(64, strlen($event->ip_hash));
        $this->assertFalse(str_contains(json_encode($event), $user->email));
        $this->assertFalse(str_contains(json_encode($event), 'incorrect-password'));
    }
}
