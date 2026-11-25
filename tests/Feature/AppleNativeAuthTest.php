<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AppleNativeAuthTest extends TestCase
{
    use RefreshDatabase;

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function signed(string $nonce, array $overrides = []): string
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($private)['rsa'];
        Http::fake(['https://appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kid' => 'fixture', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $this->b64($rsa['n']), 'e' => $this->b64($rsa['e']),
        ]]])]);
        $header = $this->b64(json_encode(['kid' => 'fixture', 'alg' => 'RS256']));
        $claims = $this->b64(json_encode(array_replace([
            'iss' => 'https://appleid.apple.com', 'aud' => 'br.com.fluxosistemas.repasse',
            'iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp,
            'nonce' => $nonce, 'sub' => 'apple-qa-subject', 'email' => 'apple-qa@example.test',
            'email_verified' => 'true',
        ], $overrides)));
        openssl_sign($header.'.'.$claims, $signature, $private, OPENSSL_ALGO_SHA256);

        return $header.'.'.$claims.'.'.$this->b64($signature);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('apple-native-qa-', 4));
        config()->set('services.apple_native.enabled', true);
        config()->set('services.apple_native.audience', 'br.com.fluxosistemas.repasse');
    }

    public function test_valid_registration_and_replay_rejection(): void
    {
        $nonce = $this->postJson('/api/v1/auth/apple/challenge')->assertOk()->json('data.nonce');
        $payload = ['nonce' => $nonce, 'identity_token' => $this->signed($nonce), 'intent' => 'register', 'terms_accepted' => true];
        $this->postJson('/api/v1/auth/apple', $payload)->assertOk()->assertJsonStructure(['access_token']);
        $this->postJson('/api/v1/auth/apple', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('social_accounts', ['provider' => 'apple', 'provider_id' => 'apple-qa-subject']);
    }

    public function test_wrong_audience_and_existing_email_are_rejected(): void
    {
        User::factory()->create(['email' => 'apple-qa@example.test']);
        $nonce = $this->postJson('/api/v1/auth/apple/challenge')->assertOk()->json('data.nonce');
        $this->postJson('/api/v1/auth/apple', ['nonce' => $nonce, 'identity_token' => $this->signed($nonce, ['aud' => 'other.app']), 'intent' => 'register', 'terms_accepted' => true])->assertUnprocessable();
        $other = $this->postJson('/api/v1/auth/apple/challenge')->assertOk()->json('data.nonce');
        $this->postJson('/api/v1/auth/apple', ['nonce' => $other, 'identity_token' => $this->signed($other), 'intent' => 'register', 'terms_accepted' => true])->assertUnprocessable();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_disabled_provider_rejects_challenge(): void
    {
        config()->set('services.apple_native.enabled', false);
        $this->postJson('/api/v1/auth/apple/challenge')->assertStatus(503);
    }
}
