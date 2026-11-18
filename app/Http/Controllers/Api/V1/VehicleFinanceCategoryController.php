<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDB;
use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use App\Services\VehicleFinanceWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VehicleFinanceCategoryController extends Controller
{
    public function __construct(
        private readonly CaseOperations $operations,
        private readonly VehicleFinanceWorkspace $finance,
    ) {}

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in(['all', 'expense', 'income'])],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);
        $query = TenantDB::table('repasse_financial_categories')
            ->select('id', 'kind', 'name', 'slug', 'is_active', 'created_at', 'updated_at');
        if (($filters['kind'] ?? 'all') !== 'all') $query->where('kind', $filters['kind']);
        if (! ($filters['include_inactive'] ?? false)) $query->where('is_active', true);

        return response()->json(['data' => $query->orderBy('kind')->orderBy('name')->get()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['kind', 'name']);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['expense', 'income'])],
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $name = trim($data['name']);
        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => 'Informe um nome válido.']);
        }
        $slug = $this->finance->categorySlug($name);
        try {
            $id = DB::transaction(function () use ($request, $case, $data, $name, $slug): int {
                $this->operations->editable($case);
                $id = TenantDB::table('repasse_financial_categories')->insertGetId([
                    'kind' => $data['kind'], 'name' => $name, 'slug' => $slug,
                    'tenant_id' => \App\Support\TenantContext::id(),
                    'is_active' => true,
                    'created_by' => $request->user('api')->getAuthIdentifier(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->operations->event($case, $request, 'vehicle_fin_category_created');

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe essa categoria para a mesma natureza.']);
        }

        return response()->json(['data' => $this->category($id)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, int $case, int $category): JsonResponse
    {
        $this->operations->restrict($request, ['name', 'is_active']);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['name' => 'Informe uma alteração.']);
        }
        try {
            DB::transaction(function () use ($request, $case, $category, $data): void {
                $this->operations->editable($case);
                $old = TenantDB::table('repasse_financial_categories')
                    ->where('id', $category)->lockForUpdate()->firstOrFail();
                $changes = [];
                if (array_key_exists('name', $data)) {
                    $name = trim($data['name']);
                    if (mb_strlen($name) < 2) {
                        throw ValidationException::withMessages(['name' => 'Nome inválido.']);
                    }
                    if ($name !== $old->name) {
                        $changes['name'] = $name;
                        $changes['slug'] = $this->finance->categorySlug($name);
                    }
                }
                if (array_key_exists('is_active', $data)
                    && (bool) $data['is_active'] !== (bool) $old->is_active) {
                    $changes['is_active'] = $data['is_active'];
                }
                if ($changes === []) return;
                $changes['updated_at'] = now();
                TenantDB::table('repasse_financial_categories')->where('id', $category)->update($changes);
                $this->operations->event($case, $request, 'vehicle_fin_category_updated');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe essa categoria para a mesma natureza.']);
        }

        return response()->json(['data' => $this->category($category)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function category(int $id): object
    {
        return TenantDB::table('repasse_financial_categories')
            ->where('id', $id)
            ->select('id', 'kind', 'name', 'slug', 'is_active', 'created_at', 'updated_at')
            ->firstOrFail();
    }
}
