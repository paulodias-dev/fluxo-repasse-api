<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantInvitationRequest;
use App\Models\Role;
use App\Models\TenantInvitation;
use App\Services\PublicRegistrationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TenantInvitationController extends Controller
{
    public function __construct(private readonly PublicRegistrationService $registrations) {}

    public function index(): JsonResponse
    {
        $invitations = TenantInvitation::query()
            ->where('tenant_id', TenantContext::requireId())
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'email', 'role_id', 'expires_at', 'accepted_at', 'revoked_at', 'created_at'])
            ->map(fn (TenantInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_id' => $invitation->role_id,
                'expires_at' => $invitation->expires_at,
                'accepted_at' => $invitation->accepted_at,
                'revoked_at' => $invitation->revoked_at,
                'created_at' => $invitation->created_at,
            ]);

        return response()->json(['data' => $invitations->values()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(StoreTenantInvitationRequest $request): JsonResponse
    {
        $actor = $request->user('api');
        $role = Role::query()
            ->where('tenant_id', TenantContext::requireId())
            ->whereKey($request->validated('role_id'))
            ->with('permissions')
            ->firstOrFail();

        if ($role->slug === 'platform_admin' ||
            $role->permissions->pluck('key')->diff($actor->grantedPermissions())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role_id' => ['Não é permitido atribuir este papel.'],
            ]);
        }

        $result = $this->registrations->invite($request->validated(), $actor, $request);

        return response()->json([
            'message' => 'Convite enviado para '.$result['invitation']->email.'.',
            'data' => [
                'id' => $result['invitation']->id,
                'email' => $result['invitation']->email,
                'role_id' => $result['invitation']->role_id,
                'expires_at' => $result['invitation']->expires_at,
            ],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, TenantInvitation $invitation): JsonResponse
    {
        abort_unless((int) $invitation->tenant_id === TenantContext::requireId(), 404);

        if ($invitation->accepted_at !== null) {
            return response()->json(['message' => 'Convites aceitos não podem ser revogados.'], 422);
        }

        $invitation->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Convite revogado.'])
            ->header('Cache-Control', 'no-store');
    }
}
