<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FCM HTTP v1: no Firebase service account, project ID, or token in logs.
 * Fixed Google endpoints prevent user-controlled SSRF.
 */
final class MobilePushSender
{
    private function credentials(): array
    {
        $file = (string) config('services.mobile_push.credentials_file');
        $project = (string) config('services.mobile_push.project_id');
        if ($file === '' || ! is_file($file) || ! is_readable($file) ||
            ! preg_match('/^[a-zA-Z0-9-]{4,64}$/', $project)) {
            throw new RuntimeException('Mobile push credentials not configured.');
        }

        $json = file_get_contents($file);
        $account = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($account) ||
            ($account['type'] ?? null) !== 'service_account' ||
            ($account['project_id'] ?? null) !== $project ||
            empty($account['client_email']) || empty($account['private_key'])) {
            throw new RuntimeException('Mobile push credentials invalid.');
        }

        return $account;
    }

    private function accessToken(array $service): string
    {
        $project = $service['project_id'];
        $key = 'mobile-push:oauth:'.hash('sha256', $service['client_email'].':'.$project);

        return Cache::remember($key, now()->addMinutes(48), static function () use ($service): string {
            $now = time();
            $base64 = static fn (string $value): string =>
                rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $header = $base64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $payload = $base64(json_encode([
                'iss' => $service['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3500,
            ], JSON_THROW_ON_ERROR));
            $signed = $header.'.'.$payload;
            if (openssl_sign($signed, $signature, $service['private_key'], OPENSSL_ALGO_SHA256) !== true) {
                throw new RuntimeException('Unable to sign mobile push OAuth assertion.');
            }
            $assertion = $signed.'.'.$base64($signature);
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('Mobile push OAuth request failed.');
            }

            return $token;
        });
    }

    /**
     * @return string 'sent' or 'invalid' (caller revokes an invalid token)
     */
    public function send(string $token): string
    {
        if (! config('services.mobile_push.enabled')) {
            return 'disabled';
        }

        $service = $this->credentials();
        $project = (string) $service['project_id'];
        $response = Http::withToken($this->accessToken($service))
            ->timeout(12)->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                'message' => [
                    'token' => $token,
                    // Do not include message body, sender, vehicle or tenant details.
                    'notification' => [
                        'title' => 'Fluxo Repasse',
                        'body' => 'Você tem uma nova atividade. Abra o aplicativo para consultar.',
                    ],
                    'data' => ['route' => 'connections'],
                    'android' => ['priority' => 'NORMAL',
                        'notification' => ['channel_id' => 'fluxo_activity']],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);
        if ($response->successful()) {
            return 'sent';
        }

        $details = json_encode($response->json('error.details') ?? [], JSON_THROW_ON_ERROR);
        if (str_contains($details, 'UNREGISTERED') ||
            str_contains($details, 'SENDER_ID_MISMATCH')) {
            return 'invalid';
        }

        // Let the queued job retry transient failures, without logging token.
        throw new RuntimeException('Mobile push delivery failed (HTTP '.$response->status().').');
    }
}
