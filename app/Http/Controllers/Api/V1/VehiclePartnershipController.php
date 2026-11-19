<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use App\Support\TenantDB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VehiclePartnershipController extends Controller
{
    public function __construct(private readonly CaseOperations $operations) {}

    public function index(int $case): JsonResponse
    {
        $vehicle = $this->operations->ensureExists($case);
        $rows = TenantDB::table('repasse_case_partnerships as partnership')
            ->join('parties as party', 'party.id', '=', 'partnership.party_id')
            ->where('partnership.repasse_case_id', $case)
            ->select([
                'partnership.id', 'partnership.party_id', 'partnership.amount_cents',
                'partnership.created_at', 'party.name', 'party.type',
            ])
            ->orderBy('partnership.id')->get();

        $total = (int) $rows->sum(fn ($row): int => (int) $row->amount_cents);
        $sale = $vehicle->sale_amount_cents === null ? null : (int) $vehicle->sale_amount_cents;
        $data = $rows->map(function ($row) use ($total, $sale): array {
            $amount = (int) $row->amount_cents;
            $shareBasisPoints = $total > 0 ? intdiv($amount * 10000, $total) : 0;

            return [
                'id' => (int) $row->id,
                'party_id' => (int) $row->party_id,
                'name' => $row->name,
                'type' => $row->type,
                'amount_cents' => $amount,
                'share_basis_points' => $shareBasisPoints,
                'projected_share_cents' => $sale === null || $total === 0
                    ? null : intdiv($sale * $amount, $total),
                'created_at' => $row->created_at,
            ];
        })->values();

        return response()->json([
            'data' => [
                'total_investment_cents' => $total,
                'sale_amount_cents' => $sale,
                'entries' => $data,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['party_id', 'amount_cents']);
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ]);

        DB::transaction(function () use ($request, $case, $data): void {
            $this->operations->editable($case);
            $party = TenantDB::table('parties')->where('id', $data['party_id'])->first();
            if (! $party || ! $party->is_active) {
                throw ValidationException::withMessages(['party_id' => 'Cadastre uma pessoa ou empresa ativa.']);
            }
            if (TenantDB::table('repasse_case_partnerships')
                ->where('repasse_case_id', $case)->where('party_id', $data['party_id'])->exists()) {
                throw ValidationException::withMessages(['party_id' => 'Este cadastro já possui uma parceria neste veículo.']);
            }

            TenantDB::table('repasse_case_partnerships')->insert([
                'repasse_case_id' => $case,
                'party_id' => $data['party_id'],
                'amount_cents' => $data['amount_cents'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'partnership_created');
        });

        return $this->index($case)->setStatusCode(201);
    }

    public function destroy(Request $request, int $case, int $partnership): JsonResponse
    {
        DB::transaction(function () use ($request, $case, $partnership): void {
            $this->operations->editable($case);
            $row = TenantDB::table('repasse_case_partnerships')
                ->where('repasse_case_id', $case)->where('id', $partnership)
                ->lockForUpdate()->first();
            if (! $row) {
                abort(404);
            }
            TenantDB::table('repasse_case_partnerships')->where('id', $partnership)->delete();
            $this->operations->event($case, $request, 'partnership_removed');
        });

        return $this->index($case);
    }
}
