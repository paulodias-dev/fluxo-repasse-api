<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class VehicleCatalogStore
{
    public function provider(): string
    {
        return (string) config('services.vehicle_catalog.provider', 'parallelum');
    }

    public function catalog(string $type, string $resource, ?string $brand = null,
        ?string $year = null, ?string $family = null): ?array
    {
        $base = DB::table('vehicle_catalog_brands as b')
            ->where('b.provider', $this->provider())
            ->where('b.vehicle_type', $type)
            ->where('b.is_active', true);

        if ($resource === 'brands') {
            $items = $base->select(['b.external_code as id', 'b.name as label'])
                ->orderBy('b.name')->get()->map(fn ($row) => (array) $row)->all();
            return $items === [] ? null : ['status' => 'ok', 'items' => $items];
        }

        $brandRow = $base->where('b.external_code', (string) $brand)->first(['b.id']);
        if (! $brandRow) return null;

        if ($resource === 'models') {
            $items = DB::table('vehicle_catalog_models as m')
                ->where('m.brand_id', $brandRow->id)
                ->where('m.provider', $this->provider())
                ->where('m.is_active', true)
                ->select(['m.family as id', 'm.family as label'])
                ->distinct()->orderBy('m.family')->get()
                ->map(fn ($row) => (array) $row)->all();
            return $items === [] ? null : ['status' => 'ok', 'items' => $items];
        }

        if ($resource === 'years') {
            $items = DB::table('vehicle_catalog_models as m')
                ->join('vehicle_catalog_years as y', function ($join): void {
                    $join->on('y.model_id', '=', 'm.id')
                        ->where('y.provider', $this->provider())
                        ->where('y.is_active', true);
                })
                ->where('m.brand_id', $brandRow->id)
                ->where('m.provider', $this->provider())
                ->where('m.family', $this->family($family ?? ''))
                ->where('m.is_active', true)
                ->select(['y.external_code as id', 'y.label'])
                ->distinct()->orderBy('y.label')->get()
                ->map(fn ($row) => (array) $row)->all();
            return $items === [] ? null : ['status' => 'ok', 'items' => $items];
        }

        if ($resource === 'versions') {
            $items = DB::table('vehicle_catalog_models as m')
                ->join('vehicle_catalog_years as y', function ($join) use ($year): void {
                    $join->on('y.model_id', '=', 'm.id')
                        ->where('y.provider', $this->provider())
                        ->where('y.external_code', (string) $year)
                        ->where('y.is_active', true);
                })
                ->where('m.brand_id', $brandRow->id)
                ->where('m.provider', $this->provider())
                ->where('m.family', $this->family($family ?? ''))
                ->where('m.is_active', true)
                ->select(['m.external_code as id', 'm.name as label'])
                ->distinct()->orderBy('m.name')->get()
                ->map(fn ($row) => (array) $row)->all();
            return $items === [] ? null : ['status' => 'ok', 'items' => $items];
        }

        return null;
    }

    public function importRemoteList(string $type, string $resource, ?string $brand,
        ?string $year, array $items): void
    {
        if ($resource === 'brands') {
            $this->importBrands($type, $items);
            return;
        }
        if (! $brand) return;

        if ($resource === 'models') {
            $this->importModels($type, $brand, $items);
            return;
        }
        if ($resource === 'versions' && $year) {
            $this->importVersionModels($type, $brand, $year, $items);
        }
    }

    public function importBrands(string $type, array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            $name = $this->text($item['name'] ?? null, 180);
            if ($code === '' || ! $name) continue;
            $now = now();
            DB::table('vehicle_catalog_brands')->upsert([[
                'provider' => $this->provider(), 'vehicle_type' => $type,
                'external_code' => $code, 'name' => $name, 'is_active' => true,
                'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]], ['provider', 'vehicle_type', 'external_code'],
                ['name', 'is_active', 'last_seen_at', 'updated_at']);
            $count++;
        }
        return $count;
    }

    public function importModels(string $type, string $brand, array $items): int
    {
        $brandId = $this->brandId($type, $brand);
        if (! $brandId) return 0;
        $count = 0;
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            $name = $this->text($item['name'] ?? null, 180);
            if ($code === '' || ! $name) continue;
            $this->upsertModel($brandId, $code, $name);
            $count++;
        }
        return $count;
    }

    public function importModelYears(string $type, string $brand, string $modelCode,
        array $years, ?string $modelName = null): int
    {
        $brandId = $this->brandId($type, $brand);
        if (! $brandId) return 0;
        $model = $this->upsertModel($brandId, $modelCode, $modelName ?: $modelCode);
        $count = 0;
        foreach ($years as $item) {
            if (! is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            $label = $this->text($item['name'] ?? null, 180);
            if ($code === '' || ! $label) continue;
            $this->upsertYear($model->id, $code, $label);
            $count++;
        }
        return $count;
    }

    public function importVersionModels(string $type, string $brand, string $year,
        array $items): int
    {
        $brandId = $this->brandId($type, $brand);
        if (! $brandId) return 0;
        $count = 0;
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            $name = $this->text($item['name'] ?? null, 180);
            if ($code === '' || ! $name) continue;
            $model = $this->upsertModel($brandId, $code, $name);
            $this->upsertYear($model->id, $year, $this->yearLabel($year));
            $count++;
        }
        return $count;
    }

    public function findQuote(string $type, string $brand, string $model, string $year): ?array
    {
        $row = DB::table('vehicle_catalog_brands as b')
            ->join('vehicle_catalog_models as m', function ($join) use ($model): void {
                $join->on('m.brand_id', '=', 'b.id')
                    ->where('m.external_code', (string) $model)
                ->where('m.provider', $this->provider())
                    ->where('m.is_active', true);
            })
            ->join('vehicle_catalog_years as y', function ($join) use ($year): void {
                $join->on('y.model_id', '=', 'm.id')
                    ->where('y.external_code', (string) $year)
                ->where('y.provider', $this->provider())
                    ->where('y.is_active', true);
            })
            ->join('vehicle_catalog_prices as p', function ($join): void {
                $join->on('p.year_id', '=', 'y.id')
                ->where('p.provider', $this->provider());
            })
            ->where('b.provider', $this->provider())->where('b.vehicle_type', $type)
            ->where('b.external_code', (string) $brand)
            ->orderByDesc('p.queried_at')
            ->first(['b.name as brand', 'm.name as model', 'm.name as vehicle_version',
                'y.model_year', 'p.fipe_code', 'p.price_cents as fipe_price_cents',
                'p.reference_month as fipe_reference_month']);
        return $row ? (array) $row + ['vehicle_type' => $type, 'fipe_source' => $this->provider()] : null;
    }

    public function importPrice(string $type, string $brand, string $model, string $year,
        array $quote): void
    {
        $brandId = $this->brandId($type, $brand);
        if (! $brandId) return;
        $modelRow = DB::table('vehicle_catalog_models')
            ->where('brand_id', $brandId)->where('provider', $this->provider())
            ->where('external_code', $model)->first();
        if (! $modelRow) {
            $modelRow = $this->upsertModel($brandId, $model, (string) ($quote['model'] ?? $model));
        }
        $yearRow = DB::table('vehicle_catalog_years')
            ->where('model_id', $modelRow->id)->where('provider', $this->provider())
            ->where('external_code', $year)->first();
        if (! $yearRow) $yearRow = $this->upsertYear($modelRow->id, $year, $this->yearLabel($year));
        $price = (int) ($quote['fipe_price_cents'] ?? 0);
        $code = (string) ($quote['fipe_code'] ?? '');
        $month = $this->text($quote['fipe_reference_month'] ?? null, 60);
        if ($price < 1 || $code === '' || ! $month) return;
        $now = now();
        DB::table('vehicle_catalog_prices')->upsert([[
            'year_id' => $yearRow->id, 'provider' => $this->provider(),
            'fipe_code' => $code, 'price_cents' => $price,
            'reference_month' => $month, 'queried_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['provider', 'year_id', 'reference_month'],
            ['fipe_code', 'price_cents', 'queried_at', 'updated_at']);
    }

    private function brandId(string $type, string $brand): ?int
    {
        $id = DB::table('vehicle_catalog_brands')
            ->where('provider', $this->provider())->where('vehicle_type', $type)
            ->where('external_code', $brand)->value('id');
        return $id ? (int) $id : null;
    }

    private function upsertModel(int $brandId, string $code, string $name): object
    {
        $now = now();
        $name = $this->text($name, 180) ?: $code;
        DB::table('vehicle_catalog_models')->upsert([[
            'brand_id' => $brandId, 'provider' => $this->provider(),
            'external_code' => $code, 'name' => $name, 'family' => $this->family($name),
            'is_active' => true, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['provider', 'brand_id', 'external_code'],
            ['name', 'family', 'is_active', 'last_seen_at', 'updated_at']);
        return DB::table('vehicle_catalog_models')->where('brand_id', $brandId)
            ->where('provider', $this->provider())->where('external_code', $code)->first();
    }

    private function upsertYear(int $modelId, string $code, string $label): object
    {
        [$modelYear, $fuelCode] = array_pad(explode('-', $code, 2), 2, null);
        $now = now();
        DB::table('vehicle_catalog_years')->upsert([[
            'model_id' => $modelId, 'provider' => $this->provider(),
            'external_code' => $code,
            'model_year' => is_numeric($modelYear) ? (int) $modelYear : null,
            'fuel_code' => $fuelCode, 'fuel_name' => $this->fuelName($label),
            'label' => $label, 'is_active' => true, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['provider', 'model_id', 'external_code'],
            ['model_year', 'fuel_code', 'fuel_name', 'label', 'is_active', 'last_seen_at', 'updated_at']);
        return DB::table('vehicle_catalog_years')->where('model_id', $modelId)
            ->where('provider', $this->provider())->where('external_code', $code)->first();
    }

    private function family(string $label): string
    {
        $words = preg_split('/\s+/u', mb_strtoupper(trim($label)));
        return mb_substr((string) ($words[0] ?? ''), 0, 80);
    }

    private function fuelName(string $label): ?string
    {
        $name = preg_replace('/^\s*[0-9]{4,5}\s+/u', '', trim($label));
        return $name ? mb_substr($name, 0, 40) : null;
    }

    private function yearLabel(string $code): string
    {
        return str_replace('-', ' · ', $code);
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) return null;
        $value = trim($value);
        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }
}
