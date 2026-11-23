<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CrossTenantCollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('cross-tenant-vehicle-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Mail::fake();
        Storage::fake('local');
    }

    private function account(?int $tenant = null, bool $manageCases = true, ?string $email = null): array
    {
        request()->attributes->remove('verified_tenant_id');
        $u = User::factory()->create([
            'password' => 'Strong-Passphrase-123!',
            ...($tenant ? ['tenant_id' => $tenant] : []),
            ...($email ? ['email' => $email] : []),
        ]);
        if ($manageCases) {
            $role = Role::query()->firstOrCreate(
                ['tenant_id' => $u->tenant_id, 'slug' => 'collaboration_qa'],
                ['name' => 'Operador de colaboração']
            );
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $u->roles()->syncWithoutDetaching([$role->id]);
        }
        $jwt = $this->postJson('/api/v1/auth/login', [
            'email' => $u->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        return [$u, $jwt];
    }

    private function vehicle(string $jwt): int
    {
        return $this->withToken($jwt)->postJson('/api/v1/repasse-cases', [
            'title' => 'Toyota Corolla compartilhável',
            'plate' => 'ABC1D23', 'brand' => 'Toyota', 'model' => 'Corolla',
            'purchase_amount_cents' => 2000000,
        ])->assertCreated()->json('data.id');
    }

    private function invite(string $jwt, int $case, string $email, bool $history, bool $write,
        string $kind = 'share'): int
    {
        return $this->withToken($jwt)->postJson("/api/v1/vehicles/$case/collaboration/invite", [
            'email' => $email, 'kind' => $kind,
            'include_history' => $history, 'allow_write' => $write,
            'confirm_history' => true, 'confirm_permissions' => true,
        ])->assertCreated()->json('data.id');
    }

    public function test_friendship_requires_consent_and_does_not_grant_vehicle_access(): void
    {
        [$a, $jwtA] = $this->account();
        [$b, $jwtB] = $this->account();
        [$outsider, $jwtOutside] = $this->account();
        $case = $this->vehicle($jwtA);

        $this->getJson('/api/v1/connections')->assertUnauthorized();
        $found = $this->withToken($jwtA)->getJson('/api/v1/connections/search?email='.urlencode($b->email))
            ->assertOk()->assertJsonPath('data.id', $b->id);
        $this->assertSame($b->id, $found->json('data.id'));
        $this->withToken($jwtA)->postJson('/api/v1/connections', ['user_id' => $b->id])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($jwtA)->postJson('/api/v1/connections', ['email' => $b->email])
            ->assertCreated();
        $this->withToken($jwtB)->getJson('/api/v1/connections')
            ->assertJsonPath('data.0.incoming', true);
        $id = $this->withToken($jwtB)->getJson('/api/v1/connections')->json('data.0.id');
        $this->withToken($jwtA)->postJson("/api/v1/connections/$id/respond", ['accept' => true])
            ->assertUnprocessable();
        $this->withToken($jwtB)->postJson("/api/v1/connections/$id/respond", ['accept' => true])
            ->assertOk();
        $this->withToken($jwtB)->patchJson("/api/v1/connections/$id/favorite", [
            'favorite' => true,
        ])->assertOk();
        $this->withToken($jwtB)->getJson('/api/v1/connections')
            ->assertJsonPath('data.0.favorite', true);

        // Mesmo amigo de outro tenant NÃO pode usar as rotas tenant-scoped.
        $this->withToken($jwtB)->getJson("/api/v1/repasse-cases/$case")->assertNotFound();
        $this->withToken($jwtB)->getJson('/api/v1/connections/invitations')
            ->assertJsonCount(0, 'shared');

        $chat = $this->withToken($jwtA)->postJson('/api/v1/connections/chats/direct', [
            'connection_id' => $id,
        ])->assertOk()->json('data.id');
        $key = (string) Str::uuid();
        $this->withToken($jwtA)->postJson("/api/v1/connections/chats/$chat/messages", [
            'body' => 'Vamos avaliar o veículo?', 'request_key' => $key,
        ])->assertCreated()->assertJsonPath('data.created', true);
        $this->withToken($jwtA)->postJson("/api/v1/connections/chats/$chat/messages", [
            'body' => 'Vamos avaliar o veículo?', 'request_key' => $key,
        ])->assertOk()->assertJsonPath('data.created', false);
        $this->assertDatabaseCount('collab_messages', 1);
        $this->withToken($jwtB)->getJson("/api/v1/connections/chats/$chat/messages")
            ->assertJsonPath('data.0.body', 'Vamos avaliar o veículo?');
        $this->withToken($jwtOutside)->getJson("/api/v1/connections/chats/$chat/messages")
            ->assertNotFound();
        $this->withToken($jwtB)->deleteJson("/api/v1/connections/$id")->assertOk();
        $this->withToken($jwtA)->getJson("/api/v1/connections/chats/$chat/messages")
            ->assertNotFound();
        $this->assertNotSame($a->id, $outsider->id);
    }

    public function test_share_requires_explicit_history_and_write_decisions_and_grant_acceptance(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account();
        [$stranger, $jwtStranger] = $this->account();
        $case = $this->vehicle($jwtOwner);
        $old = DB::table('repasse_case_financial_entries')->insertGetId([
            'tenant_id' => $owner->tenant_id, 'repasse_case_id' => $case,
            'kind' => 'expense', 'status' => 'realized',
            'category' => 'Oficina', 'description' => 'Histórico anterior privado',
            'amount_cents' => 80000, 'effective_on' => '2026-11-01',
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($jwtOwner)->postJson("/api/v1/vehicles/$case/collaboration/invite", [
            'email' => $partner->email, 'kind' => 'share', 'include_history' => false,
            'allow_write' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['confirm_history', 'confirm_permissions']);

        $invitation = $this->invite($jwtOwner, $case, $partner->email, false, true);
        $this->withToken($jwtStranger)->postJson("/api/v1/connections/invitations/$invitation/respond", [
            'accept' => true,
        ])->assertNotFound();
        $this->withToken($jwtPartner)->getJson('/api/v1/connections/invitations')
            ->assertJsonPath('incoming.0.id', $invitation)
            ->assertJsonCount(0, 'shared');
        $accepted = $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/invitations/$invitation/respond", ['accept' => true]
        )->assertOk()->assertJsonPath('data.kind', 'share');
        $grant = $accepted->json('data.grant_id');
        $chat = $accepted->json('data.chat_id');

        $this->withToken($jwtStranger)->getJson("/api/v1/shared-vehicles/$grant")->assertNotFound();
        $this->withToken($jwtPartner)->getJson("/api/v1/shared-vehicles/$grant")
            ->assertOk()->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.include_history', false)->assertJsonCount(0, 'data.entries');
        $this->withToken($jwtPartner)->postJson("/api/v1/shared-vehicles/$grant/finance", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Lavagem',
            'description' => 'Lavagem paga pelo parceiro', 'amount_cents' => 4000,
            'effective_on' => '2026-11-20', 'paid_by_user_id' => $partner->id,
            'request_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->withToken($jwtPartner)->getJson("/api/v1/shared-vehicles/$grant")
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.paid_by_user_id', $partner->id)
            ->assertJsonPath('data.entries.0.description', 'Lavagem paga pelo parceiro');
        $this->assertDatabaseHas('repasse_case_financial_entries', [
            'id' => $old, 'description' => 'Histórico anterior privado',
        ]);
        $this->withToken($jwtPartner)->getJson("/api/v1/connections/chats/$chat/messages")->assertOk();
        $this->withToken($jwtOwner)->deleteJson("/api/v1/vehicles/$case/collaboration/grants/$grant")
            ->assertOk();
        $this->withToken($jwtPartner)->getJson("/api/v1/shared-vehicles/$grant")
            ->assertNotFound();
        $this->withToken($jwtPartner)->getJson("/api/v1/connections/chats/$chat/messages")
            ->assertNotFound();
    }

    public function test_read_only_partner_cannot_edit_finance_or_read_prior_history(): void
    {
        [, $ownerJwt] = $this->account();
        [$partner, $partnerJwt] = $this->account();
        $vehicle = $this->vehicle($ownerJwt);
        $request = $this->invite($ownerJwt, $vehicle, $partner->email, false, false);
        $grant = $this->withToken($partnerJwt)->postJson("/api/v1/connections/invitations/$request/respond", [
            'accept' => true,
        ])->assertOk()->json('data.grant_id');
        $this->withToken($partnerJwt)->patchJson("/api/v1/shared-vehicles/$grant", [
            'title' => 'Não autorizado',
        ])->assertForbidden();
        $this->withToken($partnerJwt)->postJson("/api/v1/shared-vehicles/$grant/finance", [
            'kind' => 'expense',
        ])->assertForbidden();
        $this->withToken($partnerJwt)->getJson("/api/v1/repasse-cases/$vehicle")
            ->assertNotFound();
    }

    public function test_transfer_preserves_original_audit_and_shares_history_only_if_authorized(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account();
        $source = $this->vehicle($jwtOwner);
        $request = $this->invite($jwtOwner, $source, $partner->email, true, true, 'transfer');
        $result = $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/invitations/$request/respond", ['accept' => true]
        )->assertOk()->assertJsonPath('data.kind', 'transfer');
        $dest = $result->json('data.vehicle_id');

        $this->assertNotSame($source, $dest);
        $this->assertDatabaseHas('repasse_cases', [
            'id' => $source, 'tenant_id' => $owner->tenant_id, 'status' => 'archived',
        ]);
        $this->assertDatabaseHas('repasse_cases', [
            'id' => $dest, 'tenant_id' => $partner->tenant_id, 'status' => 'draft',
        ]);
        $history = DB::table('vehicle_ownership_transfers')
            ->where('source_vehicle_id', $source)->first();
        $this->withToken($jwtPartner)->getJson("/api/v1/collaboration-transfers/$history->id/history")
            ->assertOk()->assertJsonPath('data.historical_purchase_amount_cents', 2000000);
        $this->withToken($jwtOwner)->getJson("/api/v1/collaboration-transfers/$history->id/history")
            ->assertNotFound();
        $this->withToken($jwtOwner)->patchJson("/api/v1/repasse-cases/$source", [
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('vehicle_ownership_transfers', 1);
    }

    public function test_chat_idempotency_key_rejects_a_different_message(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account();
        $this->withToken($jwtOwner)->postJson('/api/v1/connections', [
            'email' => $partner->email,
        ])->assertCreated();
        $connection = $this->withToken($jwtPartner)->getJson('/api/v1/connections')
            ->assertOk()->json('data.0.id');
        $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/$connection/respond", ['accept' => true]
        )->assertOk();
        $chat = $this->withToken($jwtOwner)->postJson('/api/v1/connections/chats/direct', [
            'connection_id' => $connection,
        ])->assertOk()->json('data.id');
        $key = (string) Str::uuid();

        $this->withToken($jwtOwner)->postJson("/api/v1/connections/chats/$chat/messages", [
            'body' => 'Primeira mensagem', 'request_key' => $key,
        ])->assertCreated();
        $this->withToken($jwtOwner)->postJson("/api/v1/connections/chats/$chat/messages", [
            'body' => 'Mensagem diferente', 'request_key' => $key,
        ])->assertConflict();
        $this->assertDatabaseCount('collab_messages', 1);
        $this->withToken($jwtOwner)->postJson("/api/v1/connections/chats/$chat/messages", [
            'body' => 'Primeira mensagem', 'request_key' => $key,
        ])->assertOk()->assertJsonPath('data.created', false);
    }

    public function test_inactive_partner_cannot_be_attributed_as_actual_payer(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account();
        $vehicle = $this->vehicle($jwtOwner);
        $request = $this->invite($jwtOwner, $vehicle, $partner->email, false, true);
        $grant = $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/invitations/$request/respond", ['accept' => true]
        )->assertOk()->json('data.grant_id');
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);

        $this->withToken($jwtPartner)->postJson("/api/v1/shared-vehicles/$grant/finance", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Lanternagem',
            'description' => 'Serviço no carro', 'amount_cents' => 25000,
            'effective_on' => '2026-11-20', 'paid_by_user_id' => $owner->id,
            'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('paid_by_user_id');

        $this->assertDatabaseCount('repasse_case_financial_entries', 0);
    }

    public function test_same_tenant_restricted_invite_is_revalidated_after_role_changes(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account((int) $owner->tenant_id, false);
        $vehicle = $this->vehicle($jwtOwner);
        $invitation = $this->invite($jwtOwner, $vehicle, $partner->email, false, false);

        $role = Role::query()->firstOrCreate([
            'tenant_id' => $owner->tenant_id, 'slug' => 'late_vehicle_operator',
        ], ['name' => 'Operador concedido depois do convite']);
        $grant = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
        $role->permissions()->syncWithoutDetaching([$grant->id]);
        $partner->roles()->syncWithoutDetaching([$role->id]);

        $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/invitations/$invitation/respond", ['accept' => true]
        )->assertUnprocessable()->assertJsonValidationErrors('invitation');
        $this->assertDatabaseCount('vehicle_access_grants', 0);
        $this->assertDatabaseHas('vehicle_collaboration_requests', [
            'id' => $invitation, 'status' => 'pending',
        ]);
    }

    public function test_transfer_history_uses_cursors_instead_of_silently_truncating(): void
    {
        [$owner, $jwtOwner] = $this->account();
        [$partner, $jwtPartner] = $this->account();
        $vehicle = $this->vehicle($jwtOwner);
        for ($i = 1; $i <= 51; $i++) {
            DB::table('repasse_case_financial_entries')->insert([
                'tenant_id' => $owner->tenant_id, 'repasse_case_id' => $vehicle,
                'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
                'description' => "Despesa histórica $i", 'amount_cents' => $i * 100,
                'effective_on' => '2026-11-01', 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repasse_case_evidence')->insert([
                'tenant_id' => $owner->tenant_id, 'repasse_case_id' => $vehicle,
                'original_name' => "documento-$i.pdf",
                'storage_path' => "test/collab/history-$vehicle-$i.pdf",
                'mime' => 'application/pdf', 'size_bytes' => 100,
                'sha256' => str_repeat('a', 64),
                'document_type' => 'outros', 'document_label' => 'Histórico',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $request = $this->invite($jwtOwner, $vehicle, $partner->email, true, true, 'transfer');
        $this->withToken($jwtPartner)->postJson(
            "/api/v1/connections/invitations/$request/respond", ['accept' => true]
        )->assertOk();
        $transfer = DB::table('vehicle_ownership_transfers')
            ->where('source_vehicle_id', $vehicle)->value('id');
        $url = "/api/v1/collaboration-transfers/$transfer/history";

        $first = $this->withToken($jwtPartner)->getJson($url)->assertOk()
            ->assertJsonCount(50, 'data.entries')->assertJsonCount(50, 'data.documents')
            ->assertJsonPath('data.entries_more', true)
            ->assertJsonPath('data.documents_more', true);
        $second = $this->withToken($jwtPartner)->getJson($url
            .'?before_entry='.$first->json('data.entries_cursor')
            .'&before_document='.$first->json('data.documents_cursor'))->assertOk()
            ->assertJsonCount(1, 'data.entries')->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.entries_more', false)
            ->assertJsonPath('data.documents_more', false);
        $this->assertNotSame($first->json('data.entries.49.id'), $second->json('data.entries.0.id'));
    }
}
