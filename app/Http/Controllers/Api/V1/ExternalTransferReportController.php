<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExternalTransferReportRequest;
use App\Services\CaseOperations;
use App\Services\ExternalTransferReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExternalTransferReportController extends Controller
{
    public function __construct(
        private readonly ExternalTransferReportService $reports,
        private readonly CaseOperations $operations,
    ) {}

    private function filters(Request $request): array
    {
        $this->operations->restrict($request, ['page', 'status']);
        return $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', 'reported', 'voided'])],
        ]);
    }

    public function index(Request $request, int $case, int $term, int $proposal): JsonResponse
    {
        $filters = $this->filters($request);
        return response()->json([
            'data' => $this->reports->index($case, $term, $proposal, $filters['status'] ?? 'all'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreExternalTransferReportRequest $request, int $case, int $term, int $proposal): JsonResponse
    {
        $this->operations->restrict($request, [
            'request_key', 'expected_proposal_sha256', 'beneficiary_party_id',
            'amount_cents', 'transferred_on', 'external_reference', 'operator_note',
            'confirmed_external', 'proof',
        ]);
        $result = $this->reports->store($case, $term, $proposal, $request->validated(), $request);
        return response()->json($result, $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $case, int $term, int $proposal, int $transfer): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->show($case, $term, $proposal, $transfer),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function void(Request $request, int $case, int $term, int $proposal, int $transfer): JsonResponse
    {
        $this->operations->restrict($request, ['reason']);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        return response()->json([
            'data' => $this->reports->void(
                $case, $term, $proposal, $transfer, $data['reason'], $request
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function proof(int $case, int $term, int $proposal, int $transfer): BinaryFileResponse
    {
        return $this->reports->proof($case, $term, $proposal, $transfer);
    }

    public function export(Request $request, int $case, int $term, int $proposal): StreamedResponse
    {
        $filters = $this->filters($request);
        return $this->reports->export($case, $term, $proposal, $filters['status'] ?? 'all');
    }
}
