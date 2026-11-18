<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\TenantRoles;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class TenantInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_and_new_user_is_created_inside_same_tenant(): void
    {
        $this->seed(AdminUserSeeder::class);
        Notification::fake();

        $tenant = Tenant::query()->create([
            'name' => 'Empresa Convite', 'slug' => 'empresa-convite', 'is_active' => true,
        ]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Administrador',
            'email' => 'admin@convite.test',
            'password' => 'Senha-Forte-123!',
            'email_verified_at' => now(),
        ]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $admin->roles()->sync([$roles['tenant_admin']->id]);
        $token = auth('api')->login($admin);

        $this->withToken($token)->postJson('/api/v1/admin/invitations', [
            'email' => 'colaborador@convite.test',
            'role_id' => $roles['tenant_user']->id,
        ])->assertCreated();

        $notifiable = new User(['email' => 'colaborador@convite.test']);
        $invitationToken = '';
        Notification::assertSentTo($notifiable, TenantInvitationNotification::class,
            function (TenantInvitationNotification $notification) use (&$invitationToken): bool {
                $invitationToken = $notification->token;
                return true;
            });

        $this->postJson('/api/v1/auth/invitations/'.$invitationToken.'/accept', [
            'name' => 'Colaborador da Empresa',
            'password' => 'Outra-Senha-123!',
            'password_confirmation' => 'Outra-Senha-123!',
        ])->assertCreated()->assertJsonStructure(['access_token', 'expires_in']);

        $created = User::query()->where('email', 'colaborador@convite.test')->firstOrFail();
        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertTrue($created->roles()->where('slug', 'tenant_user')->exists());
        $this->assertSame(1, User::query()->where('email', 'colaborador@convite.test')->count());
    }
}
