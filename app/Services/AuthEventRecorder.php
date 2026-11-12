<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthEventRecorder
{
    public function record(string $event, ?Request $request = null, ?User $user = null, ?User $actor = null): void
    {
        // Não registrar e-mail, senha, corpo HTTP, token JWT nem endereço IP em claro.
        $ip = $request?->ip();

        DB::table('auth_security_events')->insert([
            'user_id' => $user?->getKey(),
            'event_type' => $event,
            'actor_user_id' => $actor?->getKey(),
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
            'created_at' => now(),
        ]);
    }
}
