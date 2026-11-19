<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class VehicleLookupService
{
    private const FIPE_URL = 'https://fipe.parallelum.com.br/api/v2';
    private const PLATE_URL = 'https://api.placaefipe.com.br/v1/placas';

    public static function normalizePlate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $plate = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
        return preg_match('/^[A-Z]{3}(?:[0-9]{4}|[0-9][A-Z][0-9]{2})$/', $plate) ? $plate : null;
    }

    public function plate(string $plate, int $userId): array
    {
        $tenant = TenantContext::requireId();
        $secret = (string) config('services.vehicle_plate.token');
        if ($secret === '') {
            return ['status' => 'unavailable', 'plate' => $plate, 'vehicle' => null,
                'quote' => null, 'alternatives' => [], 'message' => 'Consulta por placa ainda não configurada. Você pode escolher o veículo manualmente.'];
        }
        try {
            $key = 'vehicle-plate:v1:'.$tenant.':'.hash('sha256', $plate);
            $data = Cache::remember($key, now()->addHours(6), function () use ($plate, $secret) {
                $r = Http::acceptJson()->withToken($secret)->connectTimeout(2)->timeout(6)
                    ->withoutRedirecting()->get(self::PLATE_URL.'/'.$plate, ['tipo' => 'basica']);
                if ($r->status() === 404) return ['status' => 'not_found'];
                if (! $r->successful() || ! is_array($r->json()) || ($r->json('error') === true)) {
                    throw new \RuntimeException('Plate provider unavailable');
                }
                $raw = $r->json();
                // Minimize ANTES de cachear: nunca guardar CPF, proprietário,
                // município, restrições, saldos ou payload completo do fornecedor.
                $safe = array_intersect_key($raw, array_flip([
                    'marca', 'modelo', 'versao', 'ano_modelo',
                ]));
                $fipe = is_array($raw['fipe'] ?? null) ? $raw['fipe'] : [];
                $safe['fipe'] = [
                    'codigo' => $fipe['codigo'] ?? null,
                    'valor' => $fipe['valor'] ?? null,
                    'referencia' => $fipe['referencia'] ?? null,
                    'versoes' => array_values(array_map(
                        fn (array $v): array => array_intersect_key($v,
                            array_flip(['versao', 'modelo'])),
                        array_filter(is_array($fipe['versoes'] ?? null)
                            ? $fipe['versoes'] : [], 'is_array')
                    )),
                ];
                return ['status' => 'matched', 'response' => $safe];
            });
            if ($data['status'] === 'not_found') {
                return ['status' => 'not_found', 'plate' => $plate, 'vehicle' => null, 'quote' => null, 'alternatives' => []];
            }
            $raw = $data['response'];
            $vehicle = [
                'brand' => $this->safeText($raw['marca'] ?? null, 100),
                'model' => $this->safeText($raw['modelo'] ?? null, 120),
                'version' => $this->safeText($raw['versao'] ?? null, 180),
                'model_year' => $this->validYear($raw['ano_modelo'] ?? null),
                'vehicle_type' => null,
            ];
            $fipe = is_array($raw['fipe'] ?? null) ? $raw['fipe'] : [];
            $alternatives = is_array($fipe['versoes'] ?? null) ? $fipe['versoes'] : [];
            // Sem escolha exata da versão, nunca atribuir cotação arbitrária.
            $quote = null;
            if ($alternatives === [] && is_string($fipe['codigo'] ?? null)
                && preg_match('/^[0-9]{6}-[0-9]$/', $fipe['codigo'])
                && is_numeric($fipe['valor'] ?? null)
                && ($fipe['valor'] ?? 0) > 0 && is_string($fipe['referencia'] ?? null)
                && $vehicle['brand'] && $vehicle['model'] && $vehicle['model_year']) {
                $cents = (int) round((float) $fipe['valor'] * 100);
                $quote = $this->issueQuote($userId, [
                    'brand' => $vehicle['brand'], 'model' => $vehicle['model'],
                    'model_year' => $vehicle['model_year'], 'vehicle_type' => null,
                    'vehicle_version' => $vehicle['version'],
                    'fipe_code' => $fipe['codigo'],
                    'fipe_price_cents' => $cents,
                    'fipe_reference_month' => $this->safeText($fipe['referencia'], 60),
                    'fipe_source' => 'placaefipe',
                ]);
            }
            return ['status' => $alternatives !== [] ? 'ambiguous' : 'matched',
                'plate' => $plate, 'vehicle' => $vehicle, 'quote' => $quote,
                // Evitar expor payloads brutos do fornecedor ou dados de terceiros.
                'alternatives' => array_slice(array_map(fn ($item) => [
                    'label' => $this->safeText($item['versao'] ?? $item['modelo'] ?? null, 180),
                ], array_filter($alternatives, 'is_array')), 0, 15)];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'plate' => $plate, 'vehicle' => null,
                'quote' => null, 'alternatives' => [], 'message' => 'Consulta indisponível no momento. Continue pelo cadastro guiado.'];
        }
    }

    public function catalog(string $type, string $resource, ?string $brand = null,
        ?string $year = null, ?string $family = null): array
    {
        if ($resource === 'years' && $brand && $family) {
            return $this->catalogYearsForFamily($type, $brand, $family);
        }

        $path = match ($resource) {
            'brands' => "/$type/brands",
            'models' => "/$type/brands/$brand/models",
            'years' => "/$type/brands/$brand/years",
            'versions' => "/$type/brands/$brand/years/$year/models",
            default => throw new \InvalidArgumentException('Recurso inválido'),
        };
        try {
            // Catálogo impessoal; cache global limitado, nunca cachear dados de placa aqui.
            $items = Cache::remember('vehicle-fipe:v2:'.$path, now()->addHours(12),
                fn () => $this->get($path));
            $items = array_slice($items, 0, 3000);
            if ($resource === 'models') {
                $names = [];
                foreach ($items as $item) {
                    if (! is_array($item)) continue;
                    $label = $this->family((string) ($item['name'] ?? ''));
                    if ($label !== '') $names[$label] = ['id' => $label, 'label' => $label];
                }
                $result = array_values($names);
            } else {
                $result = [];
                foreach ($items as $item) {
                    if (! is_array($item)) continue;
                    $label = $this->safeText($item['name'] ?? null, 180);
                    $id = (string) ($item['code'] ?? '');
                    if ($id === '' || ! $label) continue;
                    if ($resource === 'versions' && $this->family($label) !== $family) continue;
                    $result[] = ['id' => $id, 'label' => $label];
                }
            }
            usort($result, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
            return ['status' => 'ok', 'items' => $result];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'items' => [],
                'message' => 'Catálogo indisponível. Utilize o preenchimento manual.'];
        }
    }

    private function catalogYearsForFamily(string $type, string $brand, string $family): array
    {
        $family = $this->family($family);
        $modelsPath = "/$type/brands/$brand/models";

        try {
            $models = Cache::remember('vehicle-fipe:v2:'.$modelsPath, now()->addHours(12),
                fn () => $this->get($modelsPath));
            $modelCodes = [];
            foreach ($models as $model) {
                if (! is_array($model) || $this->family((string) ($model['name'] ?? '')) !== $family) {
                    continue;
                }
                $code = (string) ($model['code'] ?? '');
                if ($code !== '') $modelCodes[$code] = true;
            }

            if ($modelCodes === []) {
                return ['status' => 'ok', 'items' => []];
            }

            $items = Cache::remember(
                'vehicle-fipe:v2:family-years:'.hash('sha256', $type.'|'.$brand.'|'.$family),
                now()->addHours(12),
                fn () => $this->fetchFamilyYears($type, $brand, array_keys($modelCodes))
            );
            usort($items, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
            return ['status' => 'ok', 'items' => $items];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'items' => [],
                'message' => 'Catálogo indisponível. Utilize o preenchimento manual.'];
        }
    }

    /** @return array<int, array{id: string, label: string}> */
    private function fetchFamilyYears(string $type, string $brand, array $modelCodes): array
    {
        $token = (string) config('services.vehicle_fipe.token');
        $responses = Http::pool(function (Pool $pool) use ($type, $brand, $modelCodes, $token) {
            $requests = [];
            foreach ($modelCodes as $modelCode) {
                $request = $pool->as((string) $modelCode)
                    ->acceptJson()->connectTimeout(2)->timeout(6)->withoutRedirecting();
                if ($token !== '') $request = $request->withHeaders(['X-Subscription-Token' => $token]);
                $requests[] = $request->get(self::FIPE_URL."/$type/brands/$brand/models/$modelCode/years");
            }
            return $requests;
        });

        $items = [];
        foreach ($responses as $response) {
            if (! $response->successful() || ! is_array($response->json())) continue;
            foreach ($response->json() as $item) {
                if (! is_array($item)) continue;
                $label = $this->safeText($item['name'] ?? null, 180);
                $id = (string) ($item['code'] ?? '');
                if ($id !== '' && $label) $items[$id] = ['id' => $id, 'label' => $label];
            }
        }
        return array_values($items);
    }

    public function quote(string $type, string $brand, string $model, string $year, int $userId): array
    {
        try {
            $raw = $this->get("/$type/brands/$brand/models/$model/years/$year", false);
            if (! is_array($raw) || ! is_string($raw['price'] ?? null)
                || ! preg_match('/^[0-9]{6}-[0-9]$/', (string) ($raw['codeFipe'] ?? ''))
                || ! is_string($raw['referenceMonth'] ?? null)) {
                throw new \RuntimeException('Cotação inválida');
            }
            $digits = preg_replace('/[^0-9]/', '', $raw['price']);
            if (! $digits || ! ctype_digit($digits) || (int) $digits < 1) {
                throw new \RuntimeException('Preço inválido');
            }
            $quote = $this->issueQuote($userId, [
                'vehicle_type' => $type,
                'brand' => $this->safeText($raw['brand'] ?? null, 100),
                'model' => $this->safeText($raw['model'] ?? null, 120),
                'vehicle_version' => $this->safeText($raw['model'] ?? null, 180),
                'model_year' => $this->validYear($raw['modelYear'] ?? null),
                'fipe_code' => $raw['codeFipe'],
                'fipe_price_cents' => (int) $digits,
                'fipe_reference_month' => $this->safeText($raw['referenceMonth'], 60),
                'fipe_source' => 'parallelum',
            ]);
            if (! $quote['brand'] || ! $quote['model'] || ! $quote['model_year']) {
                return ['status' => 'unavailable'];
            }
            return ['status' => 'matched', 'quote' => $quote];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'message' => 'Referência FIPE indisponível; cadastro manual permitido.'];
        }
    }

    public function claimQuote(string $token, int $userId, array $vehicle): ?array
    {
        $quote = Cache::get('vehicle-quote:'.hash('sha256', $token));
        if (! is_array($quote) || ($quote['tenant_id'] ?? null) !== TenantContext::requireId()
            || ($quote['user_id'] ?? null) !== $userId) return null;
        foreach (['brand', 'model', 'model_year', 'vehicle_type', 'vehicle_version'] as $key) {
            // Ausência na cotação da placa não impede o operador de corrigir tipo ou versão.
            if (($quote[$key] ?? null) !== null
                && (string) ($vehicle[$key] ?? '') !== (string) $quote[$key]) return null;
        }
        return $quote;
    }

    private function issueQuote(int $userId, array $data): array
    {
        $token = Str::random(48);
        $quote = ['tenant_id' => TenantContext::requireId(), 'user_id' => $userId] + $data;
        Cache::put('vehicle-quote:'.hash('sha256', $token), $quote, now()->addMinutes(10));
        return $data + ['token' => $token];
    }

    private function get(string $path, bool $list = true): array
    {
        $http = Http::acceptJson()->connectTimeout(2)->timeout(6)->withoutRedirecting();
        $token = (string) config('services.vehicle_fipe.token');
        if ($token !== '') $http = $http->withHeaders(['X-Subscription-Token' => $token]);
        $response = $http->get(self::FIPE_URL.$path);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Catálogo FIPE não disponível');
        }
        $value = $response->json();
        if ($list && ! array_is_list($value)) throw new \RuntimeException('Formato de catálogo inválido');
        return $value;
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) return null;
        $value = trim($value);
        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function validYear(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1900 && (int) $value <= 2100 ? (int) $value : null;
    }

    private function family(string $label): string
    {
        // Agrupamento de apresentação, sem supor correspondência entre versões.
        $words = preg_split('/\s+/u', mb_strtoupper(trim($label)));
        return mb_substr((string) ($words[0] ?? ''), 0, 60);
    }
}
