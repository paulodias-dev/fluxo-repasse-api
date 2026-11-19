<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class VehicleCatalogSyncService
{
    public function __construct(private readonly VehicleCatalogStore $store) {}

    public function sync(string $type, ?string $brandCode = null, bool $withPrices = false): array
    {
        $provider = $this->store->provider();
        $runId = (int) DB::table('vehicle_catalog_sync_runs')->insertGetId([
            'provider' => $provider, 'vehicle_type' => $type,
            'status' => 'running', 'with_prices' => $withPrices,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stats = ['brands' => 0, 'models' => 0, 'years' => 0, 'prices' => 0];

        try {
            $brands = $this->brands($type);
            $this->store->importBrands($type, $brands);
            $selectedBrands = array_values(array_filter($brands, function ($brand) use ($brandCode): bool {
                return is_array($brand) && (! $brandCode || (string) ($brand['code'] ?? '') === $brandCode);
            }));
            $stats['brands'] = count($selectedBrands);

            foreach ($selectedBrands as $brand) {
                $code = (string) ($brand['code'] ?? '');
                if ($code === '') continue;
                $models = $this->models($type, $code);
                $this->store->importModels($type, $code, $models);
                $stats['models'] += count($models);
                foreach ($models as $model) {
                    if (! is_array($model)) continue;
                    $modelCode = (string) ($model['code'] ?? '');
                    if ($modelCode === '') continue;
                    $years = $this->years($type, $code, $modelCode);
                    $stats['years'] += $this->store->importModelYears(
                        $type, $code, $modelCode, $years, (string) ($model['name'] ?? $modelCode)
                    );
                    if (! $withPrices) continue;
                    foreach ($years as $year) {
                        if (! is_array($year)) continue;
                        $yearCode = (string) ($year['code'] ?? '');
                        if ($yearCode === '') continue;
                        $quote = $this->normalizeQuote($type, $this->detail($type, $code, $modelCode, $yearCode));
                        if ($quote) {
                            $this->store->importPrice($type, $code, $modelCode, $yearCode, $quote);
                            $stats['prices']++;
                        }
                    }
                }
            }

            DB::table('vehicle_catalog_sync_runs')->where('id', $runId)->update([
                'status' => 'completed', 'brands_count' => $stats['brands'],
                'models_count' => $stats['models'], 'years_count' => $stats['years'],
                'prices_count' => $stats['prices'], 'finished_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'completed', 'run_id' => $runId] + $stats;
        } catch (\Throwable $exception) {
            DB::table('vehicle_catalog_sync_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    public function catalog(string $type, string $resource, ?string $brand = null,
        ?string $year = null, ?string $family = null): array
    {
        if ($resource === 'brands') {
            $brands = $this->brands($type);
            $this->store->importBrands($type, $brands);
            return ['status' => 'ok', 'items' => array_map(
                fn ($item) => ['id' => $item['code'], 'label' => $item['name']], $brands
            )];
        }
        if (! $brand) return ['status' => 'ok', 'items' => []];

        if ($resource === 'models') {
            $models = $this->models($type, $brand);
            $this->store->importModels($type, $brand, $models);
            $families = [];
            foreach ($models as $model) {
                $label = $this->family((string) ($model['name'] ?? ''));
                if ($label !== '') $families[$label] = ['id' => $label, 'label' => $label];
            }
            return ['status' => 'ok', 'items' => array_values($families)];
        }

        if ($resource === 'years') {
            $family = $this->family((string) $family);
            $items = [];
            foreach ($this->models($type, $brand) as $model) {
                if ($this->family((string) ($model['name'] ?? '')) !== $family) continue;
                $modelCode = (string) ($model['code'] ?? '');
                if ($modelCode === '') continue;
                $years = $this->years($type, $brand, $modelCode);
                $this->store->importModelYears($type, $brand, $modelCode, $years, (string) $model['name']);
                foreach ($years as $item) $items[(string) $item['code']] = $item;
            }
            $items = array_values($items);
            usort($items, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            return ['status' => 'ok', 'items' => array_map(fn ($item) => [
                'id' => $item['code'], 'label' => $item['name'],
            ], $items)];
        }

        if ($resource === 'versions' && $year) {
            $models = $this->modelsForYear($type, $brand, $year);
            $this->store->importVersionModels($type, $brand, $year, $models);
            $family = $this->family((string) $family);
            return ['status' => 'ok', 'items' => array_values(array_map(
                fn ($item) => ['id' => $item['code'], 'label' => $item['name']],
                array_filter($models, fn ($item) => $this->family($item['name']) === $family)
            ))];
        }

        return ['status' => 'ok', 'items' => []];
    }

    public function quote(string $type, string $brand, string $model, string $year): ?array
    {
        return $this->normalizeQuote($type, $this->detail($type, $brand, $model, $year));
    }

    /** @return array<int, array{code: string, name: string}> */
    private function brands(string $type): array
    {
        $raw = $this->get($this->path($type));
        return $this->store->provider() === 'apifipe'
            ? $this->normalizeList($raw, 'codMarca', 'nomeMarca')
            : $this->normalizeList($raw, 'code', 'name');
    }

    /** @return array<int, array{code: string, name: string}> */
    private function models(string $type, string $brand): array
    {
        $raw = $this->get($this->path($type, $brand));
        return $this->store->provider() === 'apifipe'
            ? $this->normalizeList($raw, 'codModelo', 'nomeModelo')
            : $this->normalizeList($raw, 'code', 'name');
    }

    /** @return array<int, array{code: string, name: string}> */
    private function years(string $type, string $brand, string $model): array
    {
        $raw = $this->get($this->path($type, $brand, $model));
        return $this->store->provider() === 'apifipe'
            ? $this->normalizeList($raw, 'codAno', 'nomeAno')
            : $this->normalizeList($raw, 'code', 'name');
    }

    /** @return array<int, array{code: string, name: string}> */
    private function modelsForYear(string $type, string $brand, string $year): array
    {
        if ($this->store->provider() === 'apifipe') {
            $raw = $this->get($this->pathForYear($type, $brand, $year));
            return $this->normalizeList($raw, 'codModelo', 'nomeModelo');
        }
        $raw = $this->get("/$type/brands/$brand/years/$year/models");
        return $this->normalizeList($raw, 'code', 'name');
    }

    private function detail(string $type, string $brand, string $model, string $year): array
    {
        $raw = $this->get($this->path($type, $brand, $model, $year), false);
        if ($this->store->provider() !== 'apifipe') return $raw;
        return [
            'brand' => $raw['nomeMarca'] ?? null, 'model' => $raw['nomeModelo'] ?? null,
            'modelYear' => $raw['anoModelo'] ?? null, 'price' => $raw['valorVeiculo'] ?? null,
            'codeFipe' => $raw['codFipe'] ?? null, 'referenceMonth' => $raw['refMes'] ?? null,
        ];
    }

    private function get(string $path, bool $list = true): array
    {
        $http = Http::acceptJson()->connectTimeout(2)->timeout(10)->withoutRedirecting();
        if ($this->store->provider() === 'apifipe') {
            $token = (string) config('services.vehicle_catalog.apifipe.token');
            if ($token !== '') $http = $http->withToken($token);
            $base = rtrim((string) config('services.vehicle_catalog.apifipe.base_url'), '/');
        } else {
            $token = (string) config('services.vehicle_fipe.token');
            if ($token !== '') $http = $http->withHeaders(['X-Subscription-Token' => $token]);
            $base = 'https://fipe.parallelum.com.br/api/v2';
        }
        $response = $http->get($base.$path);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Catálogo FIPE indisponível em '.$path);
        }
        $value = $response->json();
        if ($list && ! array_is_list($value)) throw new \RuntimeException('Formato de catálogo inválido em '.$path);
        return $value;
    }

    private function path(string $type, ?string $brand = null, ?string $model = null,
        ?string $year = null): string
    {
        if ($this->store->provider() === 'apifipe') {
            $kind = ['cars' => 'carros', 'motorcycles' => 'motos', 'trucks' => 'caminhoes'][$type] ?? $type;
            return match (true) {
                $brand === null => '/'.$kind,
                $model === null => '/'.$kind.'/'.$brand,
                $year === null => '/'.$kind.'/'.$brand.'/'.$model,
                default => '/'.$kind.'/'.$brand.'/'.$model.'/'.$year,
            };
        }
        return match (true) {
            $brand === null => "/$type/brands",
            $model === null => "/$type/brands/$brand/models",
            $year === null => "/$type/brands/$brand/models/$model/years",
            default => "/$type/brands/$brand/models/$model/years/$year",
        };
    }

    private function pathForYear(string $type, string $brand, string $year): string
    {
        $kind = ['cars' => 'carros', 'motorcycles' => 'motos', 'trucks' => 'caminhoes'][$type] ?? $type;
        return '/'.$kind.'/'.$brand.'/anos/'.$year;
    }

    private function family(string $label): string
    {
        $words = preg_split('/\s+/u', mb_strtoupper(trim($label)));
        return mb_substr((string) ($words[0] ?? ''), 0, 80);
    }

    /** @return array<int, array{code: string, name: string}> */
    private function normalizeList(array $items, string $codeKey, string $nameKey): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $code = (string) ($item[$codeKey] ?? '');
            $name = trim((string) ($item[$nameKey] ?? ''));
            if ($code !== '' && $name !== '') $result[] = ['code' => $code, 'name' => $name];
        }
        return $result;
    }

    private function normalizeQuote(string $type, array $raw): ?array
    {
        if (! is_string($raw['price'] ?? null)
            || ! preg_match('/^[0-9]{6}-[0-9]$/', (string) ($raw['codeFipe'] ?? ''))
            || ! is_string($raw['referenceMonth'] ?? null)) return null;
        $digits = preg_replace('/[^0-9]/', '', $raw['price']);
        $year = $raw['modelYear'] ?? null;
        if (! $digits || ! ctype_digit($digits) || (int) $digits < 1 || ! is_numeric($year)) return null;
        return [
            'vehicle_type' => $type, 'brand' => (string) ($raw['brand'] ?? ''),
            'model' => (string) ($raw['model'] ?? ''), 'vehicle_version' => (string) ($raw['model'] ?? ''),
            'model_year' => (int) $year, 'fipe_code' => (string) $raw['codeFipe'],
            'fipe_price_cents' => (int) $digits, 'fipe_reference_month' => (string) $raw['referenceMonth'],
            'fipe_source' => $this->store->provider(),
        ];
    }
}
