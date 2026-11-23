<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CollaborationAccess;
use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VehicleCollaborationController extends Controller
{
    public function __construct(private readonly CollaborationAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $incoming = DB::table('vehicle_collaboration_requests as r')
            ->join('repasse_cases as v', function ($j) {
                $j->on('v.id', '=', 'r.vehicle_id')->on('v.tenant_id', '=', 'r.owner_tenant_id');
            })
            ->where(function ($q) use ($actor) {
                $q->where('r.recipient_id', $actor->id)
                    ->orWhere('r.recipient_email', mb_strtolower($actor->email));
            })
            ->where('r.status', 'pending')->where('r.expires_at', '>', now())
            ->select('r.id', 'r.kind', 'r.include_history', 'r.allow_write',
                'r.created_at', 'r.expires_at', 'v.title as vehicle_title', 'v.plate')
            ->orderByDesc('r.id')->limit(50)->get();
        $shared = DB::table('vehicle_access_grants as g')
            ->join('repasse_cases as v', function ($j) {
                $j->on('v.id', '=', 'g.vehicle_id')->on('v.tenant_id', '=', 'g.owner_tenant_id');
            })
            ->where('g.recipient_id', $actor->id)->where('g.status', 'active')
            ->whereNull('g.revoked_at')
            ->select('g.id', 'g.vehicle_id', 'g.include_history', 'g.allow_write',
                'v.title', 'v.plate', 'v.brand', 'v.model', 'v.status')
            ->selectSub(
                DB::table('repasse_case_evidence as photo')
                    ->select('photo.id')
                    ->whereColumn('photo.repasse_case_id', 'v.id')
                    ->whereColumn('photo.tenant_id', 'v.tenant_id')
                    ->whereNull('photo.archived_at')
                    ->where('photo.mime', 'like', 'image/%')
                    ->where(function ($inner): void {
                        $inner->where('photo.document_type', 'foto_veiculo')->orWhereNull('photo.document_type');
                    })
                    ->orderByDesc('photo.is_cover')
                    ->orderByDesc('photo.document_type')
                    ->orderBy('photo.id')
                    ->limit(1),
                'image_document_id'
            )
            ->orderByDesc('g.id')->get();
        $transfers = DB::table('vehicle_ownership_transfers')
            ->where('recipient_id', $actor->id)->where('include_history', true)
            ->orderByDesc('id')->get(['id', 'source_vehicle_id', 'target_vehicle_id', 'created_at']);
        return response()->json(['incoming' => $incoming, 'shared' => $shared, 'transfers' => $transfers])
            ->header('Cache-Control', 'no-store, private');
    }

    public function forVehicle(Request $request, int $vehicle): JsonResponse
    {
        $this->access->actor($request);
        $this->access->ownedVehicle($vehicle);
        $tenant = TenantContext::requireId();
        $grants = DB::table('vehicle_access_grants as g')
            ->join('users as u', 'u.id', '=', 'g.recipient_id')
            ->where('g.vehicle_id', $vehicle)->where('g.owner_tenant_id', $tenant)
            ->select('g.id', 'g.recipient_id', 'u.name', 'u.email', 'g.status',
                'g.include_history', 'g.allow_write', 'g.created_at')->orderByDesc('g.id')->get();
        $outgoing = DB::table('vehicle_collaboration_requests')
            ->where('owner_tenant_id', $tenant)->where('vehicle_id', $vehicle)
            ->where('status', 'pending')->orderByDesc('id')->limit(30)
            ->get(['id', 'recipient_email', 'kind', 'include_history', 'allow_write', 'expires_at']);
        return response()->json(['grants' => $grants, 'outgoing' => $outgoing])
            ->header('Cache-Control', 'no-store, private');
    }

    public function invite(Request $request, int $vehicle): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'kind' => ['required', Rule::in(['share', 'transfer'])],
            'include_history' => ['required', 'boolean'],
            'allow_write' => ['required', 'boolean'],
            'confirm_history' => ['required', 'accepted'],
            'confirm_permissions' => ['required', 'accepted'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        if ($email === mb_strtolower($actor->email)) {
            throw ValidationException::withMessages(['email' => 'Escolha outro usuário.']);
        }
        if ($data['kind'] === 'transfer' && ! $data['allow_write']) {
            throw ValidationException::withMessages(['allow_write' => 'A transferência concede a titularidade ao destinatário.']);
        }
        $recipient = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)->first(['id', 'email', 'tenant_id']);
        if ($recipient && (int) $recipient->tenant_id === TenantContext::requireId()) {
            if ($data['kind'] === 'transfer' && ! $data['include_history']) {
                throw ValidationException::withMessages(['include_history' =>
                    'Nesta empresa a transferência sem histórico não pode garantir isolamento por usuário.']);
            }
            if ($data['kind'] === 'share' &&
                (! $data['include_history'] || ! $data['allow_write'])) {
                $recipientUser = \App\Models\User::query()->find($recipient->id);
                if ($recipientUser && $recipientUser->hasPermission('cases.manage')) {
                    throw ValidationException::withMessages(['email' =>
                        'Este usuário já possui acesso operacional a todos os veículos da empresa pelo papel atual. Para restringir histórico ou edição, ajuste primeiro as permissões do usuário.']);
                }
            }
        }
        $token = Str::random(64);
        $id = DB::transaction(function () use ($actor, $vehicle, $data, $email, $recipient, $token) {
            $owner = TenantDB::table('repasse_cases')->where('id', $vehicle)
                ->lockForUpdate()->firstOrFail();
            if ($owner->status !== 'draft') {
                throw ValidationException::withMessages(['vehicle' => 'Somente veículos ativos podem ser compartilhados.']);
            }
            $pending = DB::table('vehicle_collaboration_requests')
                ->where('owner_tenant_id', TenantContext::requireId())->where('vehicle_id', $vehicle)
                ->where('recipient_email', $email)->where('status', 'pending')
                ->where('expires_at', '>', now())->exists();
            if ($pending) throw ValidationException::withMessages(['email' => 'Já existe um convite pendente para este endereço.']);
            return DB::table('vehicle_collaboration_requests')->insertGetId([
                'owner_tenant_id' => TenantContext::requireId(), 'vehicle_id' => $vehicle,
                'sender_id' => $actor->id, 'recipient_id' => $recipient?->id,
                'recipient_email' => $email, 'token_hash' => hash('sha256', $token),
                'kind' => $data['kind'], 'include_history' => $data['include_history'],
                'allow_write' => $data['allow_write'], 'status' => 'pending',
                'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        if ($recipient) {
            $this->access->notify((int) $recipient->id, 'vehicle_invitation', [
                'request_id' => $id, 'kind' => $data['kind'],
            ]);
        }
        DB::table('repasse_case_events')->insert([
            'tenant_id' => TenantContext::requireId(), 'repasse_case_id' => $vehicle,
            'actor_user_id' => $actor->id, 'event_type' => 'collaboration_invited',
            'created_at' => now(),
        ]);
        $url = rtrim((string) config('app.frontend_url'), '/').'/conexoes/convite?token='.urlencode($token);
        // Email de convite funciona inclusive para parceiros não cadastrados.
        try {
            Mail::raw(
                'Você recebeu um convite de colaboração no Fluxo Repasse. '
                .'Entre na plataforma ou crie sua conta e acesse: '.$url
                .' . Convite válido por 7 dias. Permissões e histórico dependem do aceite.',
                fn ($message) => $message->to($email)->subject('Convite de veículo — Fluxo Repasse')
            );
            $sent = true;
        } catch (\Throwable $e) {
            report($e);
            $sent = false;
        }
        return response()->json(['data' => ['id' => $id, 'email_sent' => $sent],
            'message' => $sent ? 'Convite enviado.' : 'Convite registrado, mas o e-mail não pôde ser enviado.'], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, int $invitation): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        $out = DB::transaction(function () use ($request, $actor, $invitation, $data) {
            $invite = DB::table('vehicle_collaboration_requests')
                ->where('id', $invitation)->lockForUpdate()->firstOrFail();
            abort_unless((int) $invite->recipient_id === (int) $actor->id ||
                mb_strtolower($invite->recipient_email) === mb_strtolower($actor->email), 404);
            if ($invite->status !== 'pending' || $invite->expires_at <= now()) {
                throw ValidationException::withMessages(['invitation' => 'Convite expirado ou respondido.']);
            }
            if (! $data['accept']) {
                DB::table('vehicle_collaboration_requests')->where('id', $invite->id)->update([
                    'status' => 'declined', 'resolved_at' => now(), 'updated_at' => now(),
                ]);
                $this->access->notify($invite->sender_id, 'vehicle_declined', ['request_id' => $invite->id]);
                return ['status' => 'declined'];
            }
            $vehicle = DB::table('repasse_cases')->where('id', $invite->vehicle_id)
                ->where('tenant_id', $invite->owner_tenant_id)->whereNull('admin_deleted_at')
                ->lockForUpdate()->firstOrFail();
            if ($vehicle->status !== 'draft') {
                throw ValidationException::withMessages(['vehicle' => 'O veículo não está mais disponível.']);
            }
            // A permissão do destinatário pode mudar nos sete dias do convite.
            // Revalidar no aceite para não oferecer leitura restrita fictícia a
            // alguém que já possui cases.manage irrestrito dentro do tenant.
            if ($invite->kind === 'share' &&
                (int) $invite->owner_tenant_id === (int) $actor->tenant_id &&
                (! $invite->include_history || ! $invite->allow_write) &&
                $actor->hasPermission('cases.manage')) {
                throw ValidationException::withMessages(['invitation' =>
                    'Este usuário já possui acesso irrestrito aos veículos da empresa. Ajuste seu papel antes de aceitar compartilhamento limitado.']);
            }
            if ($invite->kind === 'transfer') {
                $result = $this->completeTransfer($invite, $vehicle, $actor);
            } else {
                $result = $this->completeShare($invite, $vehicle, $actor);
            }
            DB::table('vehicle_collaboration_requests')->where('id', $invite->id)->update([
                'status' => 'accepted', 'recipient_id' => $actor->id,
                'resolved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repasse_case_events')->insert([
                'tenant_id' => $invite->owner_tenant_id, 'repasse_case_id' => $invite->vehicle_id,
                'actor_user_id' => $actor->id, 'event_type' => 'collaboration_accepted',
                'created_at' => now(),
            ]);
            $this->access->notify($invite->sender_id, 'vehicle_accepted', [
                'request_id' => $invite->id, 'kind' => $invite->kind,
            ]);
            return $result;
        }, 3);
        return response()->json(['data' => $out])->header('Cache-Control', 'no-store, private');
    }

    public function acceptByToken(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'accept' => ['required', 'boolean'],
        ]);
        $invite = DB::table('vehicle_collaboration_requests')
            ->where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($invite && mb_strtolower($invite->recipient_email) === mb_strtolower($actor->email), 404);
        $request->merge(['accept' => $data['accept']]);
        return $this->decide($request, (int) $invite->id);
    }

    private function completeShare(object $invite, object $vehicle, object $actor): array
    {
        $entryFloor = (int) DB::table('repasse_case_financial_entries')
            ->where('tenant_id', $invite->owner_tenant_id)->where('repasse_case_id', $vehicle->id)->max('id');
        $docFloor = (int) DB::table('repasse_case_evidence')
            ->where('tenant_id', $invite->owner_tenant_id)->where('repasse_case_id', $vehicle->id)->max('id');
        $existing = DB::table('vehicle_access_grants')->where('vehicle_id', $vehicle->id)
            ->where('recipient_id', $actor->id)->lockForUpdate()->first();
        $payload = [
            'owner_tenant_id' => $invite->owner_tenant_id,
            'vehicle_id' => $vehicle->id, 'recipient_id' => $actor->id,
            'include_history' => $invite->include_history,
            'allow_write' => $invite->allow_write, 'entry_floor_id' => $entryFloor,
            'document_floor_id' => $docFloor, 'status' => 'active',
            'revoked_at' => null, 'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('vehicle_access_grants')->where('id', $existing->id)->update($payload);
            $grant = $existing->id;
        } else {
            $grant = DB::table('vehicle_access_grants')->insertGetId([
                ...$payload, 'created_at' => now(),
            ]);
        }
        $ownerUser = (int) $vehicle->created_by ?: (int) $invite->sender_id;
        $chat = $this->access->vehicleGroup((int) $vehicle->id,
            (int) $invite->owner_tenant_id, $ownerUser, (int) $actor->id);
        return ['status' => 'accepted', 'kind' => 'share', 'grant_id' => $grant, 'chat_id' => $chat];
    }

    private function completeTransfer(object $invite, object $vehicle, object $actor): array
    {
        // Nova titularidade no tenant de destino, original arquivado em seu
        // tenant e preservado integralmente para auditoria, sem movê-lo por SQL.
        if (! $invite->include_history &&
            (int) $invite->owner_tenant_id === (int) $actor->tenant_id) {
            throw ValidationException::withMessages(['include_history' =>
                'Transferência sem histórico entre usuários da mesma empresa exige isolamento individual ainda indisponível.']);
        }
        $unsettled = DB::table('repasse_settlements')
            ->where('tenant_id', $invite->owner_tenant_id)
            ->where('repasse_case_id', $vehicle->id)
            ->where('status', '!=', 'cancelled')->exists();
        if ($unsettled) {
            throw ValidationException::withMessages(['vehicle' =>
                'Existe repasse financeiro não cancelado. Resolva o repasse antes de transferir a titularidade.']);
        }
        $previous = DB::table('vehicle_ownership_transfers')
            ->where('source_vehicle_id', $vehicle->id)->exists();
        if ($previous) throw ValidationException::withMessages(['vehicle' => 'Veículo já transferido.']);
        foreach (array_unique([(int) $invite->owner_tenant_id, (int) $actor->tenant_id]) as $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
        }
        DB::table('repasse_cases')->where('id', $vehicle->id)
            ->where('tenant_id', $invite->owner_tenant_id)
            ->update(['status' => 'archived', 'updated_at' => now()]);
        if ($vehicle->plate !== null && DB::table('repasse_cases')
            ->where('tenant_id', $actor->tenant_id)->where('status', 'draft')
            ->where('plate', $vehicle->plate)->exists()) {
            throw ValidationException::withMessages(['plate' => 'Já existe um veículo ativo com esta placa no destino.']);
        }
        $profile = [
            'tenant_id' => $actor->tenant_id, 'title' => $vehicle->title,
            'description' => $vehicle->description, 'status' => 'draft',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
            'plate' => $vehicle->plate, 'chassis' => $vehicle->chassis,
            'brand' => $vehicle->brand, 'model' => $vehicle->model,
            'model_year' => $vehicle->model_year, 'vehicle_type' => $vehicle->vehicle_type,
            'vehicle_version' => $vehicle->vehicle_version,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $target = DB::table('repasse_cases')->insertGetId($profile);
        DB::table('vehicle_ownership_transfers')->insert([
            'request_id' => $invite->id, 'source_tenant_id' => $invite->owner_tenant_id,
            'source_vehicle_id' => $vehicle->id, 'target_tenant_id' => $actor->tenant_id,
            'target_vehicle_id' => $target, 'recipient_id' => $actor->id,
            'include_history' => $invite->include_history, 'created_at' => now(),
        ]);
        DB::table('vehicle_access_grants')->where('vehicle_id', $vehicle->id)
            ->where('owner_tenant_id', $invite->owner_tenant_id)->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
        DB::table('collab_conversation_members')->whereIn('conversation_id',
            DB::table('collab_conversations')->where('vehicle_id', $vehicle->id)->select('id'))
            ->update(['removed_at' => now(), 'updated_at' => now()]);
        DB::table('vehicle_collaboration_requests')->where('vehicle_id', $vehicle->id)
            ->where('status', 'pending')->where('id', '<>', $invite->id)
            ->update(['status' => 'revoked', 'resolved_at' => now(), 'updated_at' => now()]);
        DB::table('repasse_case_events')->insert([
            ['tenant_id' => $invite->owner_tenant_id, 'repasse_case_id' => $vehicle->id,
                'actor_user_id' => $actor->id, 'event_type' => 'ownership_transferred', 'created_at' => now()],
            ['tenant_id' => $actor->tenant_id, 'repasse_case_id' => $target,
                'actor_user_id' => $actor->id, 'event_type' => 'ownership_received', 'created_at' => now()],
        ]);
        return ['status' => 'accepted', 'kind' => 'transfer', 'vehicle_id' => $target,
            'history_available' => (bool) $invite->include_history];
    }

    public function revoke(Request $request, int $vehicle, int $grantId): JsonResponse
    {
        $this->access->actor($request);
        $this->access->ownedVehicle($vehicle);
        DB::transaction(function () use ($vehicle, $grantId) {
            $grant = DB::table('vehicle_access_grants')->where('id', $grantId)
                ->where('owner_tenant_id', TenantContext::requireId())
                ->where('vehicle_id', $vehicle)->where('status', 'active')->lockForUpdate()->firstOrFail();
            DB::table('vehicle_access_grants')->where('id', $grant->id)->update([
                'status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now(),
            ]);
            $chat = DB::table('collab_conversations')->where('vehicle_id', $vehicle)->first();
            if ($chat) DB::table('collab_conversation_members')
                ->where('conversation_id', $chat->id)->where('user_id', $grant->recipient_id)
                ->update(['removed_at' => now(), 'updated_at' => now()]);
            DB::table('repasse_case_events')->insert([
                'tenant_id' => $grant->owner_tenant_id, 'repasse_case_id' => $vehicle,
                'actor_user_id' => $grant->recipient_id, 'event_type' => 'collaboration_revoked',
                'created_at' => now(),
            ]);
            $this->access->notify((int) $grant->recipient_id, 'vehicle_revoked', ['vehicle_id' => $vehicle]);
        });
        return response()->json(['message' => 'Acesso revogado e conversa encerrada para o participante.']);
    }

    public function resend(Request $request, int $vehicle, int $id): JsonResponse
    {
        $this->access->actor($request);
        $this->access->ownedVehicle($vehicle);
        $token = Str::random(64);
        $invite = DB::transaction(function () use ($id, $vehicle, $token) {
            $row = DB::table('vehicle_collaboration_requests')->where('id', $id)
                ->where('owner_tenant_id', TenantContext::requireId())
                ->where('vehicle_id', $vehicle)->where('status', 'pending')
                ->lockForUpdate()->firstOrFail();
            DB::table('vehicle_collaboration_requests')->where('id', $row->id)->update([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7), 'updated_at' => now(),
            ]);
            return $row;
        });
        $url = rtrim((string) config('app.frontend_url'), '/').'/conexoes/convite?token='.urlencode($token);
        try {
            Mail::raw(
                'Você recebeu um convite de colaboração no Fluxo Repasse. '
                .'Faça login ou crie sua conta e acesse: '.$url.' . Convite válido por 7 dias.',
                fn ($message) => $message->to($invite->recipient_email)
                    ->subject('Convite de veículo — Fluxo Repasse')
            );
            $sent = true;
        } catch (\Throwable $e) {
            report($e);
            $sent = false;
        }
        return response()->json(['email_sent' => $sent,
            'message' => $sent ? 'Convite reenviado.' : 'O convite foi renovado, mas o e-mail falhou.'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancelInvitation(Request $request, int $vehicle, int $id): JsonResponse
    {
        $this->access->actor($request);
        $this->access->ownedVehicle($vehicle);
        $updated = DB::table('vehicle_collaboration_requests')->where('id', $id)
            ->where('owner_tenant_id', TenantContext::requireId())
            ->where('vehicle_id', $vehicle)->where('status', 'pending')
            ->update(['status' => 'revoked', 'resolved_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404);
        return response()->json(['message' => 'Convite cancelado.']);
    }
}
