<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VehicleFinanceController extends Controller
{
    public function __construct(private readonly CaseOperations $operations) {}

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(DB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->orderByDesc('id')->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function summary(int $case): JsonResponse
    {
        $vehicle = $this->operations->ensureExists($case);
        $entries = DB::table('repasse_case_financial_entries')
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

        return response()->json(['data' => $values])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, [
            'kind', 'status', 'category', 'description', 'amount_cents', 'effective_on',
        ]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['expense', 'income'])],
            'status' => ['required', Rule::in(['planned', 'realized'])],
            'category' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $id = DB::transaction(function () use ($request, $case, $data): int {
            $this->operations->editable($case);
            $id = DB::table('repasse_case_financial_entries')->insertGetId([
                'repasse_case_id' => $case,
                'kind' => $data['kind'],
                'status' => $data['status'],
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount_cents' => $data['amount_cents'],
                'effective_on' => $data['effective_on'] ?? null,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_created');
            return $id;
        });

        return response()->json(['data' => $this->entry($case, $id)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function void(Request $request, int $case, int $entry): JsonResponse
    {
        $this->operations->restrict($request, []);
        DB::transaction(function () use ($request, $case, $entry): void {
            $this->operations->editable($case);
            $item = DB::table('repasse_case_financial_entries')
                ->where('repasse_case_id', $case)->where('id', $entry)
                ->lockForUpdate()->firstOrFail();
            if ($item->voided_at !== null) return;

            DB::table('repasse_case_financial_entries')->where('id', $entry)->update([
                'voided_at' => now(),
                'voided_by' => $request->user('api')->getAuthIdentifier(),
                'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'vehicle_entry_voided');
        });

        return response()->json(['data' => $this->entry($case, $entry)])
            ->header('Cache-Control', 'no-store');
    }

    private function entry(int $case, int $id): object
    {
        return DB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->where('id', $id)->firstOrFail();
    }
}
