<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RepasseCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = DB::table('repasse_cases')->select([
            'id', 'title', 'description', 'status', 'plate', 'brand', 'model',
            'purchased_on', 'purchase_amount_cents', 'sale_amount_cents', 'created_at', 'updated_at',
        ]);
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where(function ($search) use ($term): void {
                $search->where('title', 'like', '%'.$term.'%')
                    ->orWhere('plate', 'like', '%'.$term.'%')
                    ->orWhere('chassis', 'like', '%'.$term.'%')
                    ->orWhere('brand', 'like', '%'.$term.'%')
                    ->orWhere('model', 'like', '%'.$term.'%')
                    ->orWhere('purchase_location', 'like', '%'.$term.'%');
            });
        }
        return response()->json($query->orderByDesc('id')->paginate(15))
            ->header('Cache-Control', 'no-store');
    }

    public function show(int $case): JsonResponse
    {
        return response()->json(['data' => $this->details($case)])->header('Cache-Control', 'no-store');
    }

    /**
     * Revisão pontual, somente leitura, antes do arquivamento operacional.
     * Não é quitação, pagamento, baixa contábil nem regra que obriga venda.
     * Consulta todos os registros, sem usar páginas limitadas a 15 itens.
     */
    public function closeoutReview(int $case): JsonResponse
    {
        $vehicle = DB::table('repasse_cases')->where('id', $case)->first([
            'id', 'title', 'status', 'updated_at', 'purchase_amount_cents',
            'sale_amount_cents', 'sold_on',
        ]);
        if (! $vehicle) {
            abort(404);
        }

        $openTasks = DB::table('repasse_case_tasks')
            ->where('repasse_case_id', $case)
            ->whereIn('status', ['pending', 'doing'])->count();
        $pendingChecklist = DB::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)->whereNull('checked_at')->count();
        $documents = DB::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->whereNull('archived_at')->count();

        $money = DB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->whereNull('voided_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'realized'
                    THEN amount_cents ELSE 0 END), 0) AS realized_expenses,
                COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'realized'
                    THEN amount_cents ELSE 0 END), 0) AS realized_income,
                COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'planned'
                    THEN amount_cents ELSE 0 END), 0) AS planned_expenses,
                COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'planned'
                    THEN amount_cents ELSE 0 END), 0) AS planned_income,
                COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'planned'
                    THEN 1 ELSE 0 END), 0) AS planned_expense_count,
                COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'planned'
                    THEN 1 ELSE 0 END), 0) AS planned_income_count
            ")->first();

        $purchase = $vehicle->purchase_amount_cents === null
            ? null : (int) $vehicle->purchase_amount_cents;
        $sale = $vehicle->sale_amount_cents === null
            ? null : (int) $vehicle->sale_amount_cents;
        $realizedExpenses = (int) $money->realized_expenses;
        $realizedIncome = (int) $money->realized_income;
        $currentCost = $purchase === null ? null : $purchase + $realizedExpenses;
        $saleResult = $vehicle->sold_on === null || $sale === null || $currentCost === null
            ? null : $sale + $realizedIncome - $currentCost;

        return response()->json(['data' => [
            'id' => (int) $vehicle->id,
            'title' => $vehicle->title,
            'status' => $vehicle->status,
            'updated_at' => $vehicle->updated_at,
            'purchase_amount_cents' => $purchase,
            'sale_amount_cents' => $sale,
            'sold_on' => $vehicle->sold_on,
            'current_cost_cents' => $currentCost,
            'sale_result_cents' => $saleResult,
            'expense_realized_cents' => $realizedExpenses,
            'income_realized_cents' => $realizedIncome,
            'expense_planned_cents' => (int) $money->planned_expenses,
            'income_planned_cents' => (int) $money->planned_income,
            'expense_planned_count' => (int) $money->planned_expense_count,
            'income_planned_count' => (int) $money->planned_income_count,
            'open_tasks_count' => $openTasks,
            'pending_checklist_count' => $pendingChecklist,
            'documents_count' => $documents,
        ], 'as_of' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, ['title', 'description', 'plate', 'chassis', 'brand', 'model', 'model_year', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents']);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'plate' => ['sometimes', 'nullable', 'string','max:10'],
            'chassis' => ['sometimes', 'nullable', 'string','max:30'],
            'brand' => ['sometimes', 'nullable', 'string','max:100'],
            'model' => ['sometimes', 'nullable', 'string','max:120'],
            'model_year' => ['sometimes', 'nullable', 'integer','min:1900','max:2100'],
            'purchase_location' => ['sometimes', 'nullable', 'string','max:180'],
            'seller_name' => ['sometimes', 'nullable', 'string','max:180'],
            'buyer_name' => ['sometimes', 'nullable', 'string','max:180'],
            'purchased_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'delivered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sold_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'purchase_amount_cents' => ['sometimes', 'nullable', 'integer','min:0','max:999999999999'],
            'sale_amount_cents' => ['sometimes', 'nullable', 'integer','min:0','max:999999999999'],
        ]);
        $title = trim($data['title']);
        if (mb_strlen($title) < 2) {
            throw ValidationException::withMessages(['title' => 'Título inválido.']);
        }
        $this->assertSaleCoherence(
            $data['sold_on'] ?? null, $data['purchased_on'] ?? null,
            $data['sale_amount_cents'] ?? null,
        );
        $id = DB::transaction(function () use ($request, $data, $title): int {
            $id = DB::table('repasse_cases')->insertGetId([
                'title' => $title, 'description' => $data['description'] ?? null,
                'status' => 'draft', 'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ] + array_intersect_key($data, array_flip(['plate', 'chassis', 'brand', 'model', 'model_year', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents'])));
            $this->record($id, $request, 'case_created');
            return $id;
        });
        return response()->json(['data' => $this->details($id)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $case): JsonResponse
    {
        $this->rejectUnknown($request, ['title', 'description', 'status', 'plate', 'chassis', 'brand', 'model', 'model_year', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents']);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(['draft', 'archived'])],
            'plate' => ['sometimes', 'nullable', 'string','max:10'],
            'chassis' => ['sometimes', 'nullable', 'string','max:30'],
            'brand' => ['sometimes', 'nullable', 'string','max:100'],
            'model' => ['sometimes', 'nullable', 'string','max:120'],
            'model_year' => ['sometimes', 'nullable', 'integer','min:1900','max:2100'],
            'purchase_location' => ['sometimes', 'nullable', 'string','max:180'],
            'seller_name' => ['sometimes', 'nullable', 'string','max:180'],
            'buyer_name' => ['sometimes', 'nullable', 'string','max:180'],
            'purchased_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'delivered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sold_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'purchase_amount_cents' => ['sometimes', 'nullable', 'integer','min:0','max:999999999999'],
            'sale_amount_cents' => ['sometimes', 'nullable', 'integer','min:0','max:999999999999'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['title' => 'Informe uma alteração.']);
        }
        $changed = DB::transaction(function () use ($request, $case, $data): bool {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            $title = array_key_exists('title', $data) ? trim($data['title']) : $current->title;
            if (mb_strlen($title) < 2) {
                throw ValidationException::withMessages(['title' => 'Título inválido.']);
            }
            $description = array_key_exists('description', $data) ? $data['description'] : $current->description;
            $status = $data['status'] ?? $current->status;

            if (array_intersect(array_keys($data), ['sold_on', 'purchased_on', 'sale_amount_cents']) !== []) {
                $value = static fn (string $field) => array_key_exists($field, $data)
                    ? $data[$field] : $current->{$field};
                $this->assertSaleCoherence(
                    $value('sold_on'), $value('purchased_on'), $value('sale_amount_cents')
                );
            }
            $profileUpdates = [];
            foreach (['plate', 'chassis', 'brand', 'model', 'model_year', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents'] as $field) {
                if (array_key_exists($field, $data) &&
                    (string) ($data[$field] ?? '') !== (string) ($current->{$field} ?? '')) {
                    $profileUpdates[$field] = $data[$field];
                }
            }
            if ($current->status === 'archived' && $status === 'archived' &&
                ($title !== $current->title || $description !== $current->description || $profileUpdates !== [])) {
                throw ValidationException::withMessages(['status' => 'Reabra o dossiê para editar.']);
            }
            if ($title === $current->title && $description === $current->description && $status === $current->status && $profileUpdates === []) return false;
            DB::table('repasse_cases')->where('id', $case)->update([
                'title' => $title, 'description' => $description, 'status' => $status,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ] + $profileUpdates);
            $event = $status !== $current->status
                ? ($status === 'archived' ? 'case_archived' : 'case_reopened')
                : 'case_updated';
            $this->record($case, $request, $event);
            return true;
        });
        return response()->json(['data' => $this->details($case), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    public function attach(Request $request, int $case): JsonResponse
    {
        $this->rejectUnknown($request, ['party_id', 'description']);
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')],
            'description' => ['nullable', 'string', 'max:180'],
        ]);
        DB::transaction(function () use ($request, $case, $data): void {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $party = DB::table('parties')->where('id', $data['party_id'])->first();
            if (! $party || ! $party->is_active) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro deve estar ativo.']);
            }
            if (DB::table('repasse_case_parties')->where('repasse_case_id', $case)
                ->where('party_id', $data['party_id'])->exists()) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro já associado.']);
            }
            DB::table('repasse_case_parties')->insert([
                'repasse_case_id' => $case, 'party_id' => $data['party_id'],
                'description' => $data['description'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($case, $request, 'party_attached');
        });
        return response()->json(['data' => $this->details($case)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function detach(Request $request, int $case, int $party): JsonResponse
    {
        DB::transaction(function () use ($request, $case, $party): void {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $deleted = DB::table('repasse_case_parties')->where('repasse_case_id', $case)
                ->where('party_id', $party)->delete();
            if (! $deleted) abort(404);
            $this->record($case, $request, 'party_detached');
        });
        return response()->json(['data' => $this->details($case)])
            ->header('Cache-Control', 'no-store');
    }

    public function events(Request $request, int $case): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        if (! DB::table('repasse_cases')->where('id', $case)->exists()) abort(404);
        return response()->json([
            'events' => DB::table('repasse_case_events')->where('repasse_case_id', $case)
                ->select('id', 'event_type', 'actor_user_id', 'created_at')
                ->orderByDesc('id')->paginate(15),
        ])->header('Cache-Control', 'no-store');
    }

    private function details(int $id): array
    {
        $case = DB::table('repasse_cases')->where('id', $id)->first();
        if (! $case) abort(404);
        $parties = DB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $id)
            ->select('cp.party_id', 'cp.description', 'p.name', 'p.type', 'p.is_active')
            ->orderBy('cp.id')->get()->map(fn ($p) => [
                'party_id' => $p->party_id, 'description' => $p->description,
                'name' => $p->name, 'type' => $p->type, 'is_active' => (bool) $p->is_active,
            ]);
        return [
            'id' => $case->id, 'title' => $case->title, 'description' => $case->description,
            'status' => $case->status, 'created_at' => $case->created_at,
            'updated_at' => $case->updated_at, 'parties' => $parties,
            'plate' => $case->plate,
            'chassis' => $case->chassis,
            'brand' => $case->brand,
            'model' => $case->model,
            'model_year' => $case->model_year,
            'purchase_location' => $case->purchase_location,
            'seller_name' => $case->seller_name,
            'buyer_name' => $case->buyer_name,
            'purchased_on' => $case->purchased_on,
            'delivered_on' => $case->delivered_on,
            'sold_on' => $case->sold_on,
            'purchase_amount_cents' => $case->purchase_amount_cents,
            'sale_amount_cents' => $case->sale_amount_cents,
        ];
    }

    private function assertSaleCoherence(
        ?string $soldOn, ?string $purchasedOn, int|string|null $saleCents
    ): void {
        if ($soldOn === null) {
            return; // An undated sale amount remains a projection, not a recorded sale.
        }
        if ($saleCents === null || (int) $saleCents < 1) {
            throw ValidationException::withMessages([
                'sale_amount_cents' => 'Informe um valor de venda maior que zero para registrar a venda.',
            ]);
        }
        if ($purchasedOn !== null && $soldOn < $purchasedOn) {
            throw ValidationException::withMessages([
                'sold_on' => 'A venda não pode ter data anterior à compra.',
            ]);
        }
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknown, 'Campo não permitido neste módulo.')
            );
        }
    }

    private function record(int $case, Request $request, string $event): void
    {
        DB::table('repasse_case_events')->insert([
            'repasse_case_id' => $case, 'actor_user_id' => $request->user('api')->id,
            'event_type' => $event, 'created_at' => now(),
        ]);
    }
}
