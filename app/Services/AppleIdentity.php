<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class AppleIdentity
{
    private function audience(): string
    {
        $audience = config('services.apple_native.audience');
        abort_unless(config('services.apple_native.enabled') && is_string($audience) && $audience !== '', 503, 'Acesso Apple indisponível.');

        return $audience;
    }

    public function challenge(): string
    {
        $this->audience();
        $nonce = bin2hex(random_bytes(32));
        Cache::put('apple:nonce:'.hash('sha256', $nonce), true, now()->addMinutes(5));

        return $nonce;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['provider' => 'Não foi possível validar a identidade Apple. Inicie uma nova tentativa.']);
    }

    public function verify(string $jwt, string $nonce): array
    {
        $aud = $this->audience();
        $key = 'apple:nonce:'.hash('sha256', $nonce);
        if (! preg_match('/^[a-f0-9]{64}$/D', $nonce) || ! Cache::has($key)) {
            $this->invalid();
        }
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3 || strlen($jwt) > 8192) {
                $this->invalid();
            }
            [$header, $payload, $signature] = $parts;
            $h = json_decode(self::decode($header), true, 8, JSON_THROW_ON_ERROR);
            $claims = json_decode(self::decode($payload), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($h) || ! is_array($claims) || ($h['alg'] ?? null) !== 'RS256'
                || ! is_string($h['kid'] ?? null) || strlen($h['kid']) > 128) {
                $this->invalid();
            }
            $response = Http::acceptJson()->connectTimeout(3)->timeout(6)
                ->withOptions(['allow_redirects' => false])->get('https://appleid.apple.com/auth/keys');
            if (! $response->successful() || strlen($response->body()) > 65536) {
                $this->invalid();
            }
            $keys = $response->json('keys');
            if (! is_array($keys)) {
                $this->invalid();
            }
            $jwk = null;
            foreach ($keys as $candidate) {
                if (is_array($candidate) && ($candidate['kid'] ?? null) === $h['kid']
                    && ($candidate['kty'] ?? null) === 'RSA' && ($candidate['alg'] ?? null) === 'RS256'
                    && ($candidate['use'] ?? null) === 'sig') {
                    $jwk = $candidate;
                    break;
                }
            }
            if (! $jwk || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
                $this->invalid();
            }
            $pem = self::rsaPem(self::decode($jwk['n']), self::decode($jwk['e']));
            if (openssl_verify($header.'.'.$payload, self::decode($signature), $pem, OPENSSL_ALGO_SHA256) !== 1) {
                $this->invalid();
            }
            $time = now()->timestamp;
            if (($claims['iss'] ?? null) !== 'https://appleid.apple.com' || ($claims['aud'] ?? null) !== $aud
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $time
                || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $time + 60 || $claims['iat'] < $time - 600
                || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
                || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
                $this->invalid();
            }
            // A trava protege a leitura/remoção contra replay concorrente.
            $used = Cache::lock($key.':lock', 10)->block(3, function () use ($key): bool {
                if (! Cache::has($key)) {
                    return false;
                }
                Cache::forget($key);

                return true;
            });
            if (! $used) {
                $this->invalid();
            }

            return $claims;
        } catch (\Throwable) {
            $this->invalid();
        }
    }

    private static function decode(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('Base64url inválido.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Base64url inválido.');
        }

        return $decoded;
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return chr($tag).chr($length).$value;
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(128 + strlen($encoded)).$encoded.$value;
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            throw new \InvalidArgumentException('Inteiro RSA inválido.');
        }
        if (ord($value[0]) >= 128) {
            $value = "\x00".$value;
        }

        return self::der(2, $value);
    }

    private static function rsaPem(string $n, string $e): string
    {
        if (strlen($n) < 256 || strlen($n) > 512 || strlen($e) > 8) {
            throw new \InvalidArgumentException('Chave RSA inválida.');
        }
        $rsa = self::der(48, self::integer($n).self::integer($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = self::der(48, $algorithm.self::der(3, "\x00".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
