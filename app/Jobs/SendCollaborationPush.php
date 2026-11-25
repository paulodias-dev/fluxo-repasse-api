<?php

namespace App\Jobs;

use App\Services\MobilePushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SendCollaborationPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public readonly int $notificationId) {}

    public function handle(MobilePushSender $sender): void
    {
        if (! config('services.mobile_push.enabled')) {
            return;
        }

        $notice = DB::table('collab_notifications')
            ->where('id', $this->notificationId)->first(['id', 'recipient_id']);
        if (! $notice) {
            return;
        }

        $user = DB::table('users')->where('id', $notice->recipient_id)
            ->first(['id', 'tenant_id', 'auth_version', 'is_active']);
        if (! $user || ! $user->is_active ||
            ! DB::table('tenants')->where('id', $user->tenant_id)
                ->where('is_active', true)->exists()) {
            return;
        }

        $devices = DB::table('mobile_push_devices')
            ->where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->where('auth_version', $user->auth_version)
            ->whereNull('revoked_at')->whereNotNull('encrypted_token')
            ->limit(6)->get(['id', 'encrypted_token']);

        foreach ($devices as $device) {
            try {
                $token = Crypt::decryptString($device->encrypted_token);
            } catch (Throwable) {
                // Corrupt or stale ciphertext must be destroyed.
                $this->revoke($device->id);
                continue;
            }

            $result = $sender->send($token);
            unset($token);
            if ($result === 'invalid') {
                $this->revoke($device->id);
            }
        }
    }

    private function revoke(int $id): void
    {
        DB::table('mobile_push_devices')->where('id', $id)->update([
            'encrypted_token' => null, 'token_hash' => null,
            'revoked_at' => now(), 'updated_at' => now(),
        ]);
    }
}
