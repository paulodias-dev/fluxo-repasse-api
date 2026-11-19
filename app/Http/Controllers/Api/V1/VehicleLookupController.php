<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\VehicleLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VehicleLookupController extends Controller
{
    public function __construct(private readonly VehicleLookupService $service) {}

    public function plate(Request $request): JsonResponse
    {
        $data = $request->validate(['plate' => ['required', 'string', 'max:10']]);
        $plate = VehicleLookupService::normalizePlate($data['plate']);
        if (! $plate) return response()->json(['message' => 'Informe uma placa válida.'], 422);
        return $this->reply($this->service->plate($plate, (int) $request->user('api')->id));
    }

    public function catalog(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, ['brands', 'models', 'years', 'versions'], true), 404);
        $rules = [
            'type' => ['required', Rule::in(['cars', 'motorcycles', 'trucks'])],
            'brand' => [$resource !== 'brands' ? 'required' : 'sometimes', 'regex:/^[0-9]{1,8}$/'],
            'year' => [$resource === 'versions' ? 'required' : 'sometimes', 'regex:/^(?:19|20|21)[0-9]{2}-[1-6]$|^32000-[1-6]$/'],
            'family' => [$resource === 'versions' ? 'required' : 'sometimes', 'string', 'max:60'],
        ];
        $data = $request->validate($rules);
        return $this->reply($this->service->catalog($data['type'], $resource,
            $data['brand'] ?? null, $data['year'] ?? null, $data['family'] ?? null));
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['cars', 'motorcycles', 'trucks'])],
            'brand' => ['required', 'regex:/^[0-9]{1,8}$/'],
            'model' => ['required', 'regex:/^[0-9]{1,8}$/'],
            'year' => ['required', 'regex:/^(?:19|20|21)[0-9]{2}-[1-6]$|^32000-[1-6]$/'],
        ]);
        return $this->reply($this->service->quote($data['type'], $data['brand'],
            $data['model'], $data['year'], (int) $request->user('api')->id));
    }

    private function reply(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
