<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit, user-bound cross-tenant collaboration. Never changes TenantContext
 * or grants access to any existing tenant-scoped operational controller.
 */
final class CollaborationAccess
{
    public function actor(Request $request): object
    {
        $user = $request->user('api');
        abort_unless($user && $user->is_active && (int) $user->tenant_id === TenantContext::requireId(), 403);

        return $user;
    }

    public function ownedVehicle(int $id): object
    {
        return TenantDB::table('repasse_cases')->where('id', $id)->firstOrFail();
    }

    public function grant(Request $request, int $grantId, bool $write = false): object
    {
        $actor = $this->actor($request);
        $grant = DB::table('vehicle_access_grants as g')
            ->join('repasse_cases as v', function ($join) {
                $join->on('v.id', '=', 'g.vehicle_id')->on('v.tenant_id', '=', 'g.owner_tenant_id');
            })
            ->where('g.id', $grantId)->where('g.recipient_id', $actor->id)
            ->where('g.status', 'active')->whereNull('g.revoked_at')
            ->where('v.status', 'draft')
            ->select('g.*', 'v.title', 'v.status as vehicle_status')
            ->first();
        abort_unless($grant, 404);
        if ($write && ! $grant->allow_write) abort(403);

        return $grant;
    }

    public function activeMember(Request $request, int $conversationId): object
    {
        $actor = $this->actor($request);
        $member = DB::table('collab_conversation_members as m')
            ->join('collab_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.conversation_id', $conversationId)->where('m.user_id', $actor->id)
            ->whereNull('m.removed_at')
            ->select('m.*', 'c.kind', 'c.vehicle_id', 'c.owner_tenant_id', 'c.connection_id')
            ->first();
        abort_unless($member, 404);
        if ($member->kind === 'direct') {
            $active = DB::table('user_connections')->where('id', $member->connection_id)
                ->where('status', 'accepted')->exists();
            abort_unless($active, 404);
        } else {
            $owner = DB::table('repasse_cases')->where('id', $member->vehicle_id)
                ->where('tenant_id', $member->owner_tenant_id)->first();
            abort_unless($owner && $owner->status === 'draft', 404);
            $owns = (int) $owner->created_by === (int) $actor->id &&
                (int) $owner->tenant_id === (int) $actor->tenant_id;
            $grant = DB::table('vehicle_access_grants')
                ->where('vehicle_id', $member->vehicle_id)
                ->where('owner_tenant_id', $member->owner_tenant_id)
                ->where('recipient_id', $actor->id)
                ->where('status', 'active')->whereNull('revoked_at')->exists();
            abort_unless($owns || $grant, 404);
        }

        return $member;
    }

    public function conversationMember(int $conversationId, int $userId): void
    {
        $floor = (int) DB::table('collab_messages')
            ->where('conversation_id', $conversationId)->max('id');
        $existing = DB::table('collab_conversation_members')
            ->where('conversation_id', $conversationId)->where('user_id', $userId)->first();
        if ($existing && $existing->removed_at === null) return;
        if ($existing) {
            DB::table('collab_conversation_members')->where('id', $existing->id)->update([
                'joined_after_message_id' => $floor, 'last_read_message_id' => $floor,
                'removed_at' => null, 'updated_at' => now(),
            ]);
        } else {
            DB::table('collab_conversation_members')->insert([
                'conversation_id' => $conversationId, 'user_id' => $userId,
                'joined_after_message_id' => $floor, 'last_read_message_id' => $floor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function vehicleGroup(int $vehicleId, int $tenantId, int $ownerId, int $memberId): int
    {
        $existing = DB::table('collab_conversations')->where('kind', 'vehicle')
            ->where('vehicle_id', $vehicleId)->first();
        $id = $existing ? $existing->id : DB::table('collab_conversations')->insertGetId([
            'kind' => 'vehicle', 'vehicle_id' => $vehicleId, 'owner_tenant_id' => $tenantId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->conversationMember($id, $ownerId);
        $this->conversationMember($id, $memberId);

        return $id;
    }

    public function notify(int $userId, string $type, array $data): void
    {
        DB::table('collab_notifications')->insert([
            'recipient_id' => $userId, 'type' => $type,
            'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function financialPayer(int $vehicleId, int $ownerTenantId, int $payerId): void
    {
        $owner = DB::table('repasse_cases')->where('id', $vehicleId)
            ->where('tenant_id', $ownerTenantId)->value('created_by');
        $activePartner = DB::table('vehicle_access_grants')->where('vehicle_id', $vehicleId)
            ->where('owner_tenant_id', $ownerTenantId)->where('recipient_id', $payerId)
            ->where('status', 'active')->whereNull('revoked_at')->exists();
        if ((int) $owner !== $payerId && ! $activePartner) {
            throw ValidationException::withMessages(['paid_by_user_id' => 'Pagador não participa deste veículo.']);
        }
    }
}
