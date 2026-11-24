<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobilePushDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('mobile-push-test-secret-', 4));
        config()->set('services.mobile_push.enabled', false);
        $this->seed(AdminUserSeeder::class);
    }

    private function account(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Empresa '.$slug, 'slug' => $slug, 'is_active' => true,
        ]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Operador '.$slug,
            'email' => $slug.'@example.invalid',
            'password' => 'Senha&Segura12345!',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $user->roles()->sync([$roles['tenant_admin']->id]);

        return [$tenant, $user, auth('api')->login($user)];
    }

    private function payload(string $deviceId, string $token, array $extra = []): array
    {
        return [
            'device_id' => $deviceId, 'platform' => 'android',
            'push_token' => $token, 'consent' => true, ...$extra,
        ];
    }

    public function test_requires_auth_and_explicit_opt_in(): void
    {
        $this->getJson('/api/v1/mobile/devices')->assertUnauthorized();
        $this->postJson('/api/v1/mobile/devices', [])->assertUnauthorized();
        [, , $jwt] = $this->account('push-consent');
        $this->withToken($jwt)->postJson('/api/v1/mobile/devices', [
            'device_id' => (string) Str::uuid(), 'platform' => 'android',
            'push_token' => str_repeat('x', 48), 'consent' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_tokens_are_encrypted_and_never_returned_to_client(): void
    {
        [$tenant, $user, $jwt] = $this->account('push-private');
        $device = (string) Str::uuid();
        $token = 'fcm:'.str_repeat('Alpha123_', 16);
        $this->withToken($jwt)->postJson('/api/v1/mobile/devices',
            $this->payload($device, $token))
            ->assertOk()->assertJsonPath('data.device_id', $device)
            ->assertJsonMissingPath('data.push_token')
            ->assertJsonMissingPath('data.encrypted_token');

        $row = DB::table('mobile_push_devices')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertNotSame($token, $row->encrypted_token);
        $this->assertSame($token, Crypt::decryptString($row->encrypted_token));
        $this->assertSame(hash('sha256', $token), $row->token_hash);

        $this->withToken($jwt)->getJson('/api/v1/mobile/devices')
            ->assertOk()->assertJsonPath('data.0.device_uuid', $device)
            ->assertJsonMissingPath('data.0.encrypted_token')
            ->assertJsonMissingPath('data.0.token_hash');
        $this->withToken($jwt)->deleteJson('/api/v1/mobile/devices/'.$device)
            ->assertOk();
        $this->assertDatabaseHas('mobile_push_devices', [
            'device_uuid' => $device, 'token_hash' => null,
            'encrypted_token' => null,
        ]);
    }

    public function test_devices_from_other_tenant_cannot_be_listed_or_revoked(): void
    {
        [, , $jwtA] = $this->account('push-a');
        $a = (string) Str::uuid();
        $this->withToken($jwtA)->postJson('/api/v1/mobile/devices',
            $this->payload($a, str_repeat('A', 80)))->assertOk();

        [, , $jwtB] = $this->account('push-b');
        $b = (string) Str::uuid();
        $this->withToken($jwtB)->postJson('/api/v1/mobile/devices',
            $this->payload($b, str_repeat('B', 80)))->assertOk();
        $this->withToken($jwtB)->getJson('/api/v1/mobile/devices')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_uuid', $b);
        $this->withToken($jwtB)->deleteJson('/api/v1/mobile/devices/'.$a)
            ->assertNotFound();
        $this->withToken($jwtB)->deleteJson('/api/v1/mobile/devices/all')
            ->assertOk();
        $this->assertDatabaseHas('mobile_push_devices', [
            'device_uuid' => $a, 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('mobile_push_devices', [
            'device_uuid' => $b, 'encrypted_token' => null,
        ]);
    }

    public function test_registering_same_physical_device_on_new_account_removes_old_owner(): void
    {
        [, $userA, $jwtA] = $this->account('push-switch-a');
        $uuid = (string) Str::uuid();
        $token = 'sample:'.str_repeat('C', 96);
        $this->withToken($jwtA)->postJson('/api/v1/mobile/devices',
            $this->payload($uuid, $token))->assertOk();

        [, $userB, $jwtB] = $this->account('push-switch-b');
        $this->withToken($jwtB)->postJson('/api/v1/mobile/devices',
            $this->payload($uuid, $token))->assertOk();

        $this->assertDatabaseCount('mobile_push_devices', 1);
        $this->assertDatabaseHas('mobile_push_devices', [
            'device_uuid' => $uuid, 'user_id' => $userB->id,
        ]);
        $this->withToken($jwtA)->getJson('/api/v1/mobile/devices')
            ->assertJsonCount(0, 'data');
        $this->assertNotSame($userA->id, $userB->id);
    }
}
