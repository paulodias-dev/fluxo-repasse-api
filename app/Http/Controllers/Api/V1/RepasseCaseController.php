<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDB;
use App\Services\RepasseFinancialOverview;
use App\Services\VehicleLookupService;
use App\Support\TenantContext;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RepasseCaseController extends Controller
{
    public function __construct(private readonly RepasseFinancialOverview $repasseOverview,
        private readonly VehicleLookupService $vehicleLookup) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = TenantDB::table('repasse_cases')->select([
            'id', 'title', 'description', 'status', 'plate', 'brand', 'model',
            'purchased_on', 'purchase_amount_cents', 'sale_amount_cents', 'created_at', 'updated_at',
        ]);
        $query->whereNull('admin_deleted_at');
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
    private function closeoutSnapshot(int $case): array
    {
        $vehicle = TenantDB::table('repasse_cases')->where('id', $case)->whereNull('admin_deleted_at')->first([
            'id', 'title', 'status', 'updated_at', 'purchase_amount_cents',
            'sale_amount_cents', 'sold_on',
        ]);
        if (! $vehicle) {
            abort(404);
        }

        $openTasks = TenantDB::table('repasse_case_tasks')
            ->where('repasse_case_id', $case)
            ->whereIn('status', ['pending', 'doing'])->count();
        $pendingChecklist = TenantDB::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)->whereNull('checked_at')->count();
        $documents = TenantDB::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->whereNull('archived_at')->count();

        $money = TenantDB::table('repasse_case_financial_entries')
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
        // Fluxos externos são declarativos e não alteram a margem gerencial.
        $repasses = $this->repasseOverview->forVehicle($case);
        $currentCost = $purchase === null ? null : $purchase + $realizedExpenses;
        $saleResult = $vehicle->sold_on === null || $sale === null || $currentCost === null
            ? null : $sale + $realizedIncome - $currentCost;

        // Cursores append-only evitam aceitar um snapshot antigo quando
        // duas operações diferentes conservam os mesmos totais e contagens.
        $caseEventCursor = (int) TenantDB::table('repasse_case_events')
            ->where('repasse_case_id', $case)->max('id');
        $repasseEventCursor = (int) TenantDB::table('repasse_settlement_events as e')
            ->join('repasse_settlements as s', 's.id', '=', 'e.settlement_id')
            ->where('s.repasse_case_id', $case)->max('e.id');

        return [
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
            'case_event_cursor' => $caseEventCursor,
            'repasse_event_cursor' => $repasseEventCursor,
            // Pendências para ciência, NÃO quitação ou impeditivo absoluto de arquivo.
            'repasse_draft_count' => $repasses['controlled']['draft_count'],
            'repasse_approved_count' => $repasses['controlled']['approved_count'],
            'repasse_approved_cents' => $repasses['controlled']['approved_cents'],
            'repasse_paid_cents' => $repasses['controlled']['paid_cents'],
            'repasse_remaining_cents' => $repasses['controlled']['remaining_cents'],
            'repasse_external_direct_cents' => $repasses['external_reports']['direct_cents'],
            'repasse_external_proposal_cents' => $repasses['external_reports']['proposal_cents'],
            'repasse_overlap_needs_review' => $repasses['flags']['possible_overlap'],
            'repasse_overpaid_inconsistency' => $repasses['flags']['overpaid_inconsistency'],
        ];
    }


    private function closeoutHash(array $review): string
    {
        // Digest de leitura para concorrência; não equivale a assinatura jurídica.
        return hash('sha256', json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function closeoutReview(int $case): JsonResponse
    {
        $review = $this->closeoutSnapshot($case);
        $review['review_sha256'] = $this->closeoutHash($review);

        return response()->json([
            'data' => $review,
            'as_of' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function hasOpenCloseoutItems(array $review): bool
    {
        return (int) $review['open_tasks_count']
            + (int) $review['pending_checklist_count']
            + (int) $review['expense_planned_count']
            + (int) $review['income_planned_count']
            + (int) $review['repasse_draft_count']
            + ($review['repasse_remaining_cents'] > 0 ? 1 : 0)
            + ($review['repasse_overlap_needs_review'] ? 1 : 0)
            + ($review['repasse_overpaid_inconsistency'] ? 1 : 0) > 0;
    }

    /**
     * Deve ser invocado DENTRO de transação para impedir o TOCTOU entre
     * conferir pendências e gravar o estado do veículo.
     * CaseOperations::editable() dos demais módulos bloqueia a mesma linha.
     */
    private function verifyCloseout(int $case, string $expectedSha, bool $acknowledge): array
    {
        $vehicle = TenantDB::table('repasse_cases')->where('id', $case)
            ->lockForUpdate()->first();
        if (! $vehicle) {
            abort(404);
        }
        if ($vehicle->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Veículo já concluído. Reabra para editar.',
            ]);
        }

        $review = $this->closeoutSnapshot($case);
        if (! hash_equals($this->closeoutHash($review), $expectedSha)) {
            abort(409, 'Os custos ou pendências mudaram. Confira novamente antes de concluir.');
        }
        if ($this->hasOpenCloseoutItems($review) && ! $acknowledge) {
            throw ValidationException::withMessages([
                'acknowledge_pending' => 'Confirme a ciência das pendências antes de concluir.',
            ]);
        }

        return $review;
    }

    /**
     * Macro 47 — concluir a ficha (com ou sem venda) numa transação.
     * Continua possível encerrar veículo sem venda registrada.
     */
    public function completeVehicle(Request $request, int $case): JsonResponse
    {
        $this->rejectUnknown($request, [
            'expected_closeout_sha256', 'acknowledge_pending',
        ]);
        $data = $request->validate([
            'expected_closeout_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'acknowledge_pending' => ['required', 'boolean'],
        ]);
        $pending = DB::transaction(function () use ($request, $case, $data): bool {
            $review = $this->verifyCloseout(
                $case, $data['expected_closeout_sha256'], $data['acknowledge_pending']
            );
            TenantDB::table('repasse_cases')->where('id', $case)->update([
                'status' => 'archived',
                'updated_by' => $request->user('api')->id,
                'updated_at' => now(),
            ]);
            $this->record($case, $request, 'case_archived');

            return $this->hasOpenCloseoutItems($review);
        }, 3);

        return response()->json([
            'data' => $this->details($case),
            'pending_acknowledged' => $pending,
            'financial_authorization' => false,
            'payment_executed' => false,
            'obligation_created' => false,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Macro 47 — registrar/revisar venda e concluir o acompanhamento
     * atomicamente. NUNCA lança receita extra, dívida, quitação ou repasse.
     */
    public function completeSale(Request $request, int $case): JsonResponse
    {
        $this->rejectUnknown($request, [
            'sale_amount_cents', 'sold_on', 'buyer_name',
            'expected_closeout_sha256', 'acknowledge_pending',
        ]);
        $data = $request->validate([
            'sale_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'sold_on' => ['required', 'date_format:Y-m-d'],
            'buyer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'expected_closeout_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'acknowledge_pending' => ['required', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($request, $case, $data): array {
            $review = $this->verifyCloseout($case, $data['expected_closeout_sha256'],
                $data['acknowledge_pending']);

            $purchasedOn = TenantDB::table('repasse_cases')->where('id', $case)
                ->value('purchased_on');
            $this->assertSaleCoherence(
                $data['sold_on'], $purchasedOn, $data['sale_amount_cents']
            );
            // Uma obrigação aprovada congela os valores comerciais de origem.
            $saleChanged = $review['sold_on'] !== $data['sold_on']
                || $review['sale_amount_cents'] !== (int) $data['sale_amount_cents'];
            if ($saleChanged) {
                $this->assertNoApprovedSettlement($case);
            }

            $buyer = trim((string) ($data['buyer_name'] ?? ''));
            TenantDB::table('repasse_cases')->where('id', $case)->update([
                'sale_amount_cents' => (int) $data['sale_amount_cents'],
                'sold_on' => $data['sold_on'],
                'buyer_name' => $buyer !== '' ? $buyer : null,
                'status' => 'archived',
                'updated_by' => $request->user('api')->id,
                'updated_at' => now(),
            ]);

            // Um só evento auditável para uma única ação de usuário.
            $this->record($case, $request, 'case_sale_completed');

            return [
                'review' => $review,
                'pending_acknowledged' => $this->hasOpenCloseoutItems($review),
            ];
        }, 3);

        return response()->json([
            'data' => $this->details($case),
            'pending_acknowledged' => $result['pending_acknowledged'],
            'financial_authorization' => false,
            'payment_executed' => false,
            'obligation_created' => false,
        ])->header('Cache-Control', 'no-store, private');
    }


    public function store(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, ['title', 'description', 'plate', 'chassis', 'brand',
            'model', 'model_year', 'vehicle_type', 'vehicle_version', 'purchase_location',
            'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on',
            'purchase_amount_cents', 'sale_amount_cents', 'request_key', 'fipe_quote_token']);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:10'],
            'chassis' => ['sometimes', 'nullable', 'string', 'max:30'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_type' => ['sometimes', 'nullable', Rule::in(['cars', 'motorcycles', 'trucks'])],
            'vehicle_version' => ['sometimes', 'nullable', 'string', 'max:180'],
            'purchase_location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'seller_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'buyer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'purchased_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'delivered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sold_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'purchase_amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'sale_amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'request_key' => ['sometimes', 'uuid'],
            'fipe_quote_token' => ['sometimes', 'string', 'size:48', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $data['title'] = trim($data['title']);
        if (mb_strlen($data['title']) < 2) {
            throw ValidationException::withMessages(['title' => 'Identificação inválida.']);
        }
        $data['plate'] = $this->validatedPlate($data['plate'] ?? null);
        $this->assertSaleCoherence($data['sold_on'] ?? null,
            $data['purchased_on'] ?? null, $data['sale_amount_cents'] ?? null);

        $requestKey = $data['request_key'] ?? null;
        $quoteToken = $data['fipe_quote_token'] ?? null;
        unset($data['request_key'], $data['fipe_quote_token']);
        ksort($data);
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'|'.(string) $quoteToken);

        $created = DB::transaction(function () use ($request, $data, $requestKey,
            $payloadHash, $quoteToken): array {
            $tenant = TenantContext::requireId();
            // Serializa criações/edições de placa por empresa; evita race de duplicidade.
            DB::table('tenants')->where('id', $tenant)->lockForUpdate()->first();
            if ($requestKey !== null) {
                $existing = TenantDB::table('vehicle_create_requests')
                    ->where('request_key', $requestKey)->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_sha256, $payloadHash)) {
                        abort(409, 'Esta tentativa de cadastro já foi usada com outros dados.');
                    }
                    return ['id' => (int) $existing->repasse_case_id, 'new' => false];
                }
            }
            if ($data['plate'] !== null) $this->assertNoDuplicateActivePlate($data['plate']);
            $fipe = [];
            if ($quoteToken !== null) {
                $quote = $this->vehicleLookup->claimQuote($quoteToken,
                    (int) $request->user('api')->id, $data);
                if (! $quote) {
                    throw ValidationException::withMessages([
                        'fipe_quote_token' => 'Referência FIPE expirada ou incompatível. Consulte novamente ou salve sem FIPE.',
                    ]);
                }
                $fipe = [
                    'fipe_code' => $quote['fipe_code'],
                    'fipe_reference_month' => $quote['fipe_reference_month'],
                    'fipe_price_cents' => $quote['fipe_price_cents'],
                    'fipe_source' => $quote['fipe_source'],
                    'fipe_queried_at' => now(),
                ];
            }
            if (empty($data['sold_on'])) {
                \App\Services\CompanyPlan::check();
            }
            $id = TenantDB::table('repasse_cases')->insertGetId([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ] + array_intersect_key($data, array_flip([
                'plate', 'chassis', 'brand', 'model', 'model_year',
                'vehicle_type', 'vehicle_version', 'purchase_location', 'seller_name',
                'buyer_name', 'purchased_on', 'delivered_on', 'sold_on',
                'purchase_amount_cents', 'sale_amount_cents',
            ])) + $fipe);
            if ($requestKey !== null) TenantDB::table('vehicle_create_requests')->insert([
                'request_key' => $requestKey, 'payload_sha256' => $payloadHash,
                'repasse_case_id' => $id, 'created_by' => $request->user('api')->id,
                'created_at' => now(),
            ]);
            $this->record($id, $request, 'case_created');
            return ['id' => $id, 'new' => true];
        }, 3);

        return response()->json(['data' => $this->details($created['id']),
            'created' => $created['new']], $created['new'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    private function validatedPlate(?string $plate): ?string
    {
        if ($plate === null || trim($plate) === '') return null;
        $normalized = VehicleLookupService::normalizePlate($plate);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'plate' => 'Placa inválida. Informe AAA1234 ou AAA1A23, ou deixe sem placa.',
            ]);
        }
        return $normalized;
    }

    private function assertNoDuplicateActivePlate(string $plate, ?int $exceptId = null): void
    {
        $query = TenantDB::table('repasse_cases')
            ->where('plate', $plate)->where('status', 'draft');
        if ($exceptId !== null) $query->where('id', '!=', $exceptId);
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'plate' => 'Já existe um veículo ativo com esta placa na sua empresa.',
            ]);
        }
    }

    public function update(Request $request, int $case): JsonResponse
    {
        $this->rejectUnknown($request, ['title', 'description', 'status', 'plate', 'chassis', 'brand', 'model', 'model_year', 'vehicle_type', 'vehicle_version', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents']);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(['draft', 'archived'])],
            'plate' => ['sometimes', 'nullable', 'string','max:10'],
            'chassis' => ['sometimes', 'nullable', 'string','max:30'],
            'brand' => ['sometimes', 'nullable', 'string','max:100'],
            'model' => ['sometimes', 'nullable', 'string','max:120'],
            'model_year' => ['sometimes', 'nullable', 'integer','min:1900','max:2100'],
            'vehicle_type' => ['sometimes', 'nullable', Rule::in(['cars', 'motorcycles', 'trucks'])],
            'vehicle_version' => ['sometimes', 'nullable', 'string', 'max:180'],
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
        if (array_key_exists('plate', $data)) {
            $data['plate'] = $this->validatedPlate($data['plate']);
        }
        $changed = DB::transaction(function () use ($request, $case, $data): bool {
            DB::table('tenants')->where('id', TenantContext::requireId())->lockForUpdate()->first();
            $current = TenantDB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            $title = array_key_exists('title', $data) ? trim($data['title']) : $current->title;
            if (mb_strlen($title) < 2) {
                throw ValidationException::withMessages(['title' => 'Título inválido.']);
            }
            $newPlate = array_key_exists('plate', $data) ? $data['plate'] : $current->plate;
            $newStatus = $data['status'] ?? $current->status;
            if ($current->status === 'archived' && $newStatus === 'draft' &&
                DB::table('vehicle_ownership_transfers')
                    ->where('source_vehicle_id', $case)->exists()) {
                throw ValidationException::withMessages(['status' =>
                    'Veículo transferido: a ficha original é arquivo permanente e não pode ser reaberta.']);
            }
            $sold = array_key_exists('sold_on', $data) ? $data['sold_on'] : $current->sold_on;
            if ($newStatus === 'draft' && ! $sold && ($current->status !== 'draft' || $current->sold_on)) {
                \App\Services\CompanyPlan::check();
            }
            if ($newPlate && $newStatus === 'draft') $this->assertNoDuplicateActivePlate($newPlate, $case);
            $description = array_key_exists('description', $data) ? $data['description'] : $current->description;
            $status = $data['status'] ?? $current->status;
            if ($status === 'archived' && $current->status !== 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'Para concluir, utilize a conferência de pendências e a ação Concluir veículo.',
                ]);
            }

            if (array_intersect(array_keys($data), ['sold_on', 'purchased_on', 'sale_amount_cents']) !== []) {
                $value = static fn (string $field) => array_key_exists($field, $data)
                    ? $data[$field] : $current->{$field};
                $this->assertSaleCoherence(
                    $value('sold_on'), $value('purchased_on'), $value('sale_amount_cents')
                );
            }
            $profileUpdates = [];
            foreach (['plate', 'chassis', 'brand', 'model', 'model_year', 'vehicle_type', 'vehicle_version', 'purchase_location', 'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents'] as $field) {
                if (array_key_exists($field, $data) &&
                    (string) ($data[$field] ?? '') !== (string) ($current->{$field} ?? '')) {
                    $profileUpdates[$field] = $data[$field];
                }
            }
            if (array_intersect(
                array_keys($profileUpdates),
                ['purchase_amount_cents', 'purchased_on', 'sale_amount_cents', 'sold_on']
            ) !== []) {
                $this->assertNoApprovedSettlement($case);
            }
            // Identificação alterada? Não manter referência FIPE da versão anterior.
            if (array_intersect(array_keys($profileUpdates),
                ['plate', 'brand', 'model', 'model_year', 'vehicle_type', 'vehicle_version']) !== []) {
                $profileUpdates += [
                    'fipe_code' => null, 'fipe_reference_month' => null,
                    'fipe_price_cents' => null, 'fipe_source' => null, 'fipe_queried_at' => null,
                ];
            }
            if ($current->status === 'archived' && $status === 'archived' &&
                ($title !== $current->title || $description !== $current->description || $profileUpdates !== [])) {
                throw ValidationException::withMessages(['status' => 'Reabra o dossiê para editar.']);
            }
            if ($title === $current->title && $description === $current->description && $status === $current->status && $profileUpdates === []) return false;
            TenantDB::table('repasse_cases')->where('id', $case)->update([
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
            $current = TenantDB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $party = TenantDB::table('parties')->where('id', $data['party_id'])->first();
            if (! $party || ! $party->is_active) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro deve estar ativo.']);
            }
            if (TenantDB::table('repasse_case_parties')->where('repasse_case_id', $case)
                ->where('party_id', $data['party_id'])->exists()) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro já associado.']);
            }
            TenantDB::table('repasse_case_parties')->insert([
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
            $current = TenantDB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $payerReferenced = TenantDB::table('repasse_settlements')
                ->where('repasse_case_id', $case)->where('status', '!=', 'cancelled')
                ->where('payer_party_id', $party)->exists();
            $beneficiaryReferenced = TenantDB::table('repasse_settlement_lines as l')
                ->join('repasse_settlements as s', 's.id', '=', 'l.settlement_id')
                ->where('s.repasse_case_id', $case)->where('s.status', '!=', 'cancelled')
                ->where('l.beneficiary_party_id', $party)->exists();
            if ($payerReferenced || $beneficiaryReferenced) {
                throw ValidationException::withMessages([
                    'party_id' => 'Participante vinculado a repasse em andamento. Cancele o rascunho ou a obrigação antes de desvincular.',
                ]);
            }
            $deleted = TenantDB::table('repasse_case_parties')->where('repasse_case_id', $case)
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
        if (! TenantDB::table('repasse_cases')->where('id', $case)->whereNull('admin_deleted_at')->exists()) abort(404);
        return response()->json([
            'events' => TenantDB::table('repasse_case_events')->where('repasse_case_id', $case)
                ->select('id', 'event_type', 'actor_user_id', 'created_at')
                ->orderByDesc('id')->paginate(15),
        ])->header('Cache-Control', 'no-store');
    }

    private function details(int $id): array
    {
        $case = TenantDB::table('repasse_cases')->where('id', $id)->whereNull('admin_deleted_at')->first();
        if (! $case) abort(404);
        $parties = TenantDB::table('repasse_case_parties as cp')
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
            'vehicle_type' => $case->vehicle_type,
            'vehicle_version' => $case->vehicle_version,
            'fipe_code' => $case->fipe_code,
            'fipe_reference_month' => $case->fipe_reference_month,
            'fipe_price_cents' => $case->fipe_price_cents,
            'fipe_source' => $case->fipe_source,
            'fipe_queried_at' => $case->fipe_queried_at,
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

    /**
     * Modificações de compra/venda depois da aprovação exigem estorno e
     * cancelamento explícitos. Nunca recalcule obrigações aprovadas em silêncio.
     */
    private function assertNoApprovedSettlement(int $case): void
    {
        if (TenantDB::table('repasse_settlements')->where('repasse_case_id', $case)
            ->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages([
                'sale_amount_cents' => 'Há distribuição aprovada vinculada a este veículo. Estorne as parcelas e cancele a distribuição antes de alterar compra ou venda.',
            ]);
        }
    }

    private function assertSaleCoherence(
        ?string $soldOn, ?string $purchasedOn, int|string|null $saleCents
    ): void {
        if ($soldOn === null) {
            return; // An undated sale amount remains a projection, not a recorded sale.
        }
        if ($soldOn > now()->toDateString()) {
            throw ValidationException::withMessages([
                'sold_on' => 'A venda realizada não pode ter data futura.',
            ]);
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
        TenantDB::table('repasse_case_events')->insert([
            'repasse_case_id' => $case, 'actor_user_id' => $request->user('api')->id,
            'event_type' => $event, 'created_at' => now(),
        ]);
    }
}
