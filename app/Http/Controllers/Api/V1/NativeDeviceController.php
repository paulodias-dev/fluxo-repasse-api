<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NativeDeviceRequest;
use App\Services\CollaborationAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class NativeDeviceController extends Controller
{
    public function store(NativeDeviceRequest $request, CollaborationAccess $access): JsonResponse
    {
        abort_unless(config('services.expo_push.enabled'), 503, 'Notificações remotas indisponíveis.');
        $user = $access->actor($request);
        $token = $request->validated('token');
        $tenant = TenantContext::requireId();
        $hash = hash('sha256', $token);
        DB::transaction(function () use ($user, $token, $tenant, $hash): void {
            // Reativação explícita começa no presente: não envia notificações antigas.
            // Token globalmente único impede continuar entregando para a conta anterior.
            $cursor = (int) DB::table('collab_notifications')->where('recipient_id', $user->id)->max('id');
            DB::table('native_push_devices')->upsert([[
                'token_hash' => $hash,
                'token_ciphertext' => Crypt::encryptString($token),
                'tenant_id' => $tenant,
                'user_id' => $user->id,
                'auth_version' => $user->auth_version,
                'notification_cursor' => $cursor,
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['token_hash'], ['token_ciphertext', 'tenant_id', 'user_id', 'auth_version', 'notification_cursor', 'expires_at', 'updated_at']);
        }, 3);

        return response()->json(['data' => ['enabled' => true, 'expires_in_days' => 30]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(NativeDeviceRequest $request, CollaborationAccess $access): JsonResponse
    {
        $user = $access->actor($request);
        DB::table('native_push_devices')->where('tenant_id', TenantContext::requireId())
            ->where('user_id', $user->id)->where('token_hash', hash('sha256', $request->validated('token')))->delete();

        return response()->json(['data' => ['enabled' => false]])
            ->header('Cache-Control', 'no-store, private');
    }
}
