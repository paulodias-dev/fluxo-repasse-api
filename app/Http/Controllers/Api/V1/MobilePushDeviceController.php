<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class MobilePushDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $tenant = TenantContext::requireId();

        return response()->json(['data' => DB::table('mobile_push_devices')
            ->where('user_id', $actor->id)
            ->where('tenant_id', $tenant)
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->get(['device_uuid', 'platform', 'consented_at', 'last_seen_at']),
            'delivery_enabled' => (bool) config('services.mobile_push.enabled'),
        ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function register(Request $request): JsonResponse
    {
        // Explicit opt-in. An app must not register a token on login by default.
        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'push_token' => ['required', 'string', 'min:32', 'max:4096',
                'regex:/^[A-Za-z0-9:_\\-.]+$/'],
            'consent' => ['required', 'accepted'],
        ]);
        $actor = $request->user('api');
        $tenant = TenantContext::requireId();
        $token = $data['push_token'];
        $hash = hash('sha256', $token);
        $device = strtolower($data['device_id']);
        $now = now();

        DB::transaction(function () use ($data, $actor, $tenant, $token, $hash, $device, $now): void {
            // A physical FCM token must never remain associated with a previous
            // account, even if that account's logout was offline.
            DB::table('mobile_push_devices')
                ->where('token_hash', $hash)
                ->where('device_uuid', '!=', $device)
                ->update([
                    'token_hash' => null, 'encrypted_token' => null,
                    'revoked_at' => $now, 'updated_at' => $now,
                ]);

            $payload = [
                'user_id' => $actor->id,
                'tenant_id' => $tenant,
                'platform' => $data['platform'],
                'encrypted_token' => Crypt::encryptString($token),
                'token_hash' => $hash,
                'auth_version' => (int) $actor->auth_version,
                'consented_at' => $now, 'last_seen_at' => $now,
                'revoked_at' => null, 'updated_at' => $now,
            ];
            $existing = DB::table('mobile_push_devices')
                ->where('device_uuid', $device)->lockForUpdate()->first();
            if ($existing) {
                DB::table('mobile_push_devices')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('mobile_push_devices')->insert([
                    ...$payload, 'device_uuid' => $device, 'created_at' => $now,
                ]);
            }

            // Bounded registration: a lost/stolen device cannot accumulate
            // unlimited active records under the same account.
            $stale = DB::table('mobile_push_devices')
                ->where('user_id', $actor->id)->where('tenant_id', $tenant)
                ->whereNull('revoked_at')->orderByDesc('last_seen_at')
                ->skip(6)->pluck('id');
            if ($stale->isNotEmpty()) {
                DB::table('mobile_push_devices')->whereIn('id', $stale)->update([
                    'token_hash' => null, 'encrypted_token' => null,
                    'revoked_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return response()->json(['data' => [
            'device_id' => $device, 'platform' => $data['platform'],
            'enabled' => true,
            'delivery_enabled' => (bool) config('services.mobile_push.enabled'),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        $actor = $request->user('api');
        $updated = DB::table('mobile_push_devices')
            ->where('user_id', $actor->id)
            ->where('tenant_id', TenantContext::requireId())
            ->where('device_uuid', strtolower($deviceId))
            ->whereNull('revoked_at')
            ->update([
                'encrypted_token' => null,
                'token_hash' => null,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        abort_unless($updated > 0, 404);

        return response()->json(['message' => 'Dispositivo removido.'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        DB::table('mobile_push_devices')
            ->where('user_id', $actor->id)
            ->where('tenant_id', TenantContext::requireId())
            ->whereNull('revoked_at')
            ->update([
                'encrypted_token' => null,
                'token_hash' => null,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Notificações desativadas em todos os dispositivos.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
