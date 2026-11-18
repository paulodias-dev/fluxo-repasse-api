<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDB;
use App\Http\Controllers\Controller;
use App\Http\Requests\RealizeVehicleFinanceEntryRequest;
use App\Services\CaseOperations;
use App\Services\VehicleFinanceWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VehicleFinanceController extends Controller
{
    public function __construct(
        private readonly CaseOperations $operations,
        private readonly VehicleFinanceWorkspace $workspace,
    ) {}

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(['all', 'expense', 'income'])],
            'status' => ['sometimes', Rule::in(['all', 'planned', 'realized'])],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'include_voided' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $this->workspace->rejectInvalidDateRange($data);

        return $data;
    }

    private function entryRules(bool $replacement = false): array
    {
        $rules = [
            'kind' => ['required', Rule::in(['expense', 'income'])],
            'status' => ['required', Rule::in(['planned', 'realized'])],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category' => ['required_without:category_id', 'sometimes', 'string', 'min:2', 'max:80'],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
        ];
        if ($replacement) {
            $rules['reason'] = ['required', 'string', 'min:5', 'max:500'];
        }

        return $rules;
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $filters = $this->filters($request);
        $query = $this->workspace->filtered($case, $filters)
            ->select('repasse_case_financial_entries.*')
            ->selectSub(TenantDB::table('repasse_case_financial_documents as doc')
                ->selectRaw('COUNT(*)')->whereColumn(
                    'doc.entry_id', 'repasse_case_financial_entries.id'
                ), 'documents_count');

        return response()->json($query->orderByDesc('id')->paginate(30))
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $case, int $entry): JsonResponse
    {
        $this->operations->ensureExists($case);

        return response()->json(['data' => $this->workspace->withDocuments($case, $entry)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function events(Request $request, int $case, int $entry): JsonResponse
    {
        $this->operations->ensureExists($case);
        $this->workspace->entry($case, $entry);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(TenantDB::table('repasse_case_financial_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.repasse_case_id', $case)->where('e.entry_id', $entry)
            ->select('e.id', 'e.event_type', 'e.details', 'e.actor_user_id',
                'u.name as actor_name', 'e.created_at')
            ->orderByDesc('e.id')->paginate(30))
            ->header('Cache-Control', 'no-store, private');
    }

    public function summary(int $case): JsonResponse
    {
        $vehicle = $this->operations->ensureExists($case);
        $entries = TenantDB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->whereNull('voided_at')
            ->select('kind', 'status', DB::raw('SUM(amount_cents) AS total'))
            ->groupBy('kind', 'status')->get();

        $values = [
            'expense_realized_cents' => 0,
            'expense_planned_cents' => 0,
            'income_realized_cents' => 0,
            'income_planned_cents' => 0,
        ];
        foreach ($entries as $entry) {
            $values[$entry->kind.'_'.$entry->status.'_cents'] = (int) $entry->total;
        }

        $purchase = $vehicle->purchase_amount_cents === null
            ? null : (int) $vehicle->purchase_amount_cents;
        $sale = $vehicle->sale_amount_cents === null
            ? null : (int) $vehicle->sale_amount_cents;
        $values['purchase_amount_cents'] = $purchase;
        $values['sale_amount_cents'] = $sale;
        $values['current_cost_cents'] = $purchase === null ? null
            : $purchase + $values['expense_realized_cents'];
        $values['projected_cost_cents'] = $purchase === null ? null
            : $purchase + $values['expense_realized_cents'] + $values['expense_planned_cents'];
        $values['projected_result_cents'] = $purchase === null || $sale === null ? null
            : $sale + $values['income_realized_cents'] + $values['income_planned_cents']
                - $values['projected_cost_cents'];

        // Margem gerencial de uma venda DATADA: só custos e receitas realizados.
        // Não confundir sold_on com efetivo recebimento bancário.
        $values['sale_result_cents'] = $vehicle->sold_on === null || $purchase === null
            || $sale === null ? null
            : $sale + $values['income_realized_cents'] - $purchase
                - $values['expense_realized_cents'];

        return response()->json(['data' => $values])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, [
            'kind', 'status', 'category', 'category_id', 'description',
            'amount_cents', 'effective_on',
        ]);
        $data = $request->validate($this->entryRules());
        $id = DB::transaction(function () use ($request, $case, $data): int {
            $this->operations->editable($case);
            $category = $this->workspace->category($data);
            $id = $this->createEntry($case, $request, $data, $category);
            $this->workspace->event($case, $id, $request, 'created', [
                'amount_cents' => (int) $data['amount_cents'],
                'kind' => $data['kind'], 'status' => $data['status'],
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_created');

            return $id;
        });

        return response()->json(['data' => $this->workspace->entry($case, $id)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function replace(Request $request, int $case, int $entry): JsonResponse
    {
        $this->operations->restrict($request, [
            'kind', 'status', 'category', 'category_id', 'description',
            'amount_cents', 'effective_on', 'reason',
        ]);
        $data = $request->validate($this->entryRules(true));
        $reason = trim($data['reason']);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Justifique a retificação.']);
        }

        $id = DB::transaction(function () use ($request, $case, $entry, $data, $reason): int {
            $this->operations->editable($case);
            $old = TenantDB::table('repasse_case_financial_entries')
                ->where('repasse_case_id', $case)->where('id', $entry)
                ->lockForUpdate()->firstOrFail();
            if ($old->voided_at !== null) {
                throw ValidationException::withMessages([
                    'entry' => 'O lançamento já foi estornado ou substituído.',
                ]);
            }
            $category = $this->workspace->category($data);
            $newId = $this->createEntry($case, $request, $data, $category, $entry);
            TenantDB::table('repasse_case_financial_entries')->where('id', $entry)
                ->update([
                    'voided_at' => now(), 'voided_by' => $request->user('api')->getAuthIdentifier(),
                    'void_reason' => $reason, 'updated_at' => now(),
                ]);

            $this->workspace->event($case, $entry, $request, 'replaced', [
                'replacement_entry_id' => $newId, 'reason' => $reason,
            ]);
            $this->workspace->event($case, $newId, $request, 'created_from_replacement', [
                'replaces_entry_id' => $entry, 'reason' => $reason,
                'amount_cents' => (int) $data['amount_cents'],
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_replaced');

            return $newId;
        });

        return response()->json([
            'data' => $this->workspace->entry($case, $id),
            'replaces_entry_id' => $entry,
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Confirm that a planned expense or extra income has been performed.
     * Keep the SAME entry and private evidence. An event records the prior
     * status/date. This is a declaration, not a bank settlement.
     */
    public function realize(RealizeVehicleFinanceEntryRequest $request, int $case, int $entry): JsonResponse
    {
        $this->operations->restrict($request, ['effective_on']);
        $data = $request->validated();

        $changed = DB::transaction(function () use ($request, $case, $entry, $data): bool {
            $this->operations->editable($case);
            $record = TenantDB::table('repasse_case_financial_entries')
                ->where('repasse_case_id', $case)->where('id', $entry)
                ->lockForUpdate()->firstOrFail();

            if ($record->voided_at !== null) {
                throw ValidationException::withMessages([
                    'entry' => 'Lançamento estornado ou substituído não pode ser realizado.',
                ]);
            }

            if ($record->status === 'realized') {
                if ($record->effective_on === $data['effective_on']) {
                    return false; // Safe double tap / retry, no duplicate events.
                }

                abort(409, 'Lançamento já realizado com data diferente. Use Retificar.');
            }

            if ($record->status !== 'planned') {
                throw ValidationException::withMessages([
                    'status' => 'Somente despesas e receitas previstas podem ser realizadas.',
                ]);
            }

            TenantDB::table('repasse_case_financial_entries')->where('id', $entry)->update([
                'status' => 'realized',
                'effective_on' => $data['effective_on'],
                'updated_at' => now(),
            ]);
            $this->workspace->event($case, $entry, $request, 'realized', [
                'previous_status' => 'planned',
                'new_status' => 'realized',
                'previous_effective_on' => $record->effective_on,
                'effective_on' => $data['effective_on'],
                'kind' => $record->kind,
                'amount_cents' => (int) $record->amount_cents,
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_realized');

            return true;
        }, 3);

        return response()->json([
            'data' => $this->workspace->entry($case, $entry),
            'changed' => $changed,
            'payment_executed' => false,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function void(Request $request, int $case, int $entry): JsonResponse
    {
        $this->operations->restrict($request, ['reason']);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $case, $entry, $data): void {
            $this->operations->editable($case);
            $item = TenantDB::table('repasse_case_financial_entries')
                ->where('repasse_case_id', $case)->where('id', $entry)
                ->lockForUpdate()->firstOrFail();
            if ($item->voided_at !== null) return;

            TenantDB::table('repasse_case_financial_entries')->where('id', $entry)->update([
                'voided_at' => now(), 'void_reason' => trim($data['reason'] ?? '') ?: null,
                'voided_by' => $request->user('api')->getAuthIdentifier(),
                'updated_at' => now(),
            ]);
            $this->workspace->event($case, $entry, $request, 'voided', [
                'reason' => trim($data['reason'] ?? '') ?: null,
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_voided');
        });

        return response()->json(['data' => $this->workspace->entry($case, $entry)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function report(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $filters = $this->filters($request);

        return response()->json(['data' => $this->workspace->report($case, $filters)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, int $case): StreamedResponse
    {
        $this->operations->ensureExists($case);
        $filters = $this->filters($request);
        $query = $this->workspace->filtered($case, $filters);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'ID', 'Natureza', 'Situação', 'Categoria', 'Descrição', 'Centavos',
                'Data efetiva', 'Estornado em', 'Justificativa', 'Substitui ID', 'Criado em',
            ], ';');
            $query->orderBy('id')->chunkById(200, function ($rows) use ($stream): void {
                foreach ($rows as $r) {
                    fputcsv($stream, [
                        $r->id, $r->kind, $r->status, $this->safe($r->category),
                        $this->safe($r->description), $r->amount_cents,
                        $r->effective_on, $r->voided_at, $this->safe($r->void_reason),
                        $r->replaces_entry_id, $r->created_at,
                    ], ';');
                }
            });
            fclose($stream);
        }, "veiculo-{$case}-lancamentos-".now()->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function createEntry(
        int $case, Request $request, array $data, array $category, ?int $replaces = null
    ): int {
        $description = trim($data['description']);
        if (mb_strlen($description) < 2) {
            throw ValidationException::withMessages(['description' => 'Descrição inválida.']);
        }

        return TenantDB::table('repasse_case_financial_entries')->insertGetId([
            'repasse_case_id' => $case, 'kind' => $data['kind'],
            'status' => $data['status'],
            'category' => $category['category'], 'category_id' => $category['category_id'],
            'description' => $description, 'amount_cents' => $data['amount_cents'],
            'effective_on' => $data['effective_on'] ?? null,
            'replaces_entry_id' => $replaces,
            'created_by' => $request->user('api')->getAuthIdentifier(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function safe(?string $raw): string
    {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', $raw ?? ''));
        return preg_match('/^[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }
}
