<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class NativePushDelivery
{
    /** Best effort, at-most-once attempts. The in-app inbox remains authoritative. */
    public function run(): array
    {
        if (! config('services.expo_push.enabled')) {
            return ['attempted' => 0, 'accepted' => 0, 'failed' => 0];
        }
        $result = ['attempted' => 0, 'accepted' => 0, 'failed' => 0];
        DB::table('native_push_devices')->where('expires_at', '<=', now())->delete();
        // CLI deliberately joins the exact user + tenant and auth_version, not a tenant supplied by a client.
        DB::table('native_push_devices as d')
            ->join('users as u', function ($join): void {
                $join->on('u.id', '=', 'd.user_id')->on('u.tenant_id', '=', 'd.tenant_id')
                    ->on('u.auth_version', '=', 'd.auth_version');
            })
            ->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('u.is_active', true)->where('t.is_active', true)
            ->where('d.expires_at', '>', now())
            ->select('d.id')->chunkById(100, function ($devices) use (&$result): void {
                foreach ($devices as $device) {
                    $claim = DB::transaction(function () use ($device): ?object {
                        $row = DB::table('native_push_devices')->where('id', $device->id)
                            ->where('expires_at', '>', now())->lockForUpdate()->first();
                        if (! $row) {
                            return null;
                        }
                        $active = DB::table('users as u')->join('tenants as t', 't.id', '=', 'u.tenant_id')
                            ->where('u.id', $row->user_id)->where('u.tenant_id', $row->tenant_id)
                            ->where('u.auth_version', $row->auth_version)
                            ->where('u.is_active', true)->where('t.is_active', true)->exists();
                        if (! $active) {
                            return null;
                        }
                        $latest = (int) DB::table('collab_notifications')->where('recipient_id', $row->user_id)
                            ->where('id', '>', $row->notification_cursor)->whereNull('read_at')->max('id');
                        if (! $latest) {
                            return null;
                        }
                        // Advance before network I/O: a timeout must not duplicate delivery.
                        DB::table('native_push_devices')->where('id', $row->id)
                            ->update(['notification_cursor' => $latest, 'updated_at' => now()]);
                        return $row;
                    }, 3);
                    if (! $claim) {
                        continue;
                    }
                    $result['attempted']++;
                    try {
                        $token = Crypt::decryptString($claim->token_ciphertext);
                        $client = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8);
                        if ($access = config('services.expo_push.access_token')) {
                            $client = $client->withToken($access);
                        }
                        // Never include names, messages, amounts, vehicle IDs, emails or invitation tokens.
                        $response = $client->post('https://exp.host/--/api/v2/push/send', [
                            'to' => $token,
                            'title' => 'Fluxo Repasse',
                            'body' => 'Você tem uma nova atualização. Abra o aplicativo para consultar.',
                            'data' => ['screen' => 'connections'],
                            'ttl' => 300,
                        ]);
                        $ticket = $response->json('data');
                        if ($response->successful() && data_get($ticket, 'status') === 'ok') {
                            $result['accepted']++;
                        } else {
                            $result['failed']++;
                            if (data_get($ticket, 'details.error') === 'DeviceNotRegistered') {
                                DB::table('native_push_devices')->where('id', $claim->id)
                                    ->where('token_ciphertext', $claim->token_ciphertext)->delete();
                            }
                        }
                    } catch (\Throwable) {
                        // Do not log HTTP exceptions: they may include device tokens.
                        $result['failed']++;
                    }
                }
            }, 'd.id', 'id');

        return $result;
    }
}
