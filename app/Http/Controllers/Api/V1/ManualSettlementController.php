<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManualPaymentRequest;
use App\Http\Requests\StoreManualSettlementRequest;
use App\Services\CaseOperations;
use App\Services\ManualSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ManualSettlementController extends Controller
{
    public function __construct(
        private readonly ManualSettlementService $service,
        private readonly CaseOperations $operations,
    ) {}

    public function options(int $case): JsonResponse
    {
        return $this->respond($this->service->options($case));
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, []);
        return $this->respond($this->service->index($case));
    }

    public function show(int $case, int $plan): JsonResponse
    {
        return $this->respond($this->service->show($case, $plan));
    }

    public function create(StoreManualSettlementRequest $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, [
            'request_key', 'payer_party_id', 'gross_amount_cents', 'mode',
            'deductions', 'lines',
        ]);
        return $this->respond(
            $this->service->create($case, $request->validated(), $request), 201
        );
    }

    public function approve(Request $request, int $case, int $plan): JsonResponse
    {
        $this->operations->restrict($request, ['expected_snapshot_sha256']);
        $data = $request->validate([
            'expected_snapshot_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        return $this->respond($this->service->approve(
            $case, $plan, $data['expected_snapshot_sha256'], $request
        ));
    }

    public function cancel(Request $request, int $case, int $plan): JsonResponse
    {
        return $this->respond($this->service->cancel(
            $case, $plan, $this->reason($request), $request
        ));
    }

    public function report(StoreManualPaymentRequest $request, int $case, int $plan): JsonResponse
    {
        $this->operations->restrict($request, [
            'request_key', 'beneficiary_party_id', 'amount_cents',
            'paid_on', 'external_reference', 'confirmed_external', 'proof',
        ]);
        return $this->respond($this->service->report(
            $case, $plan, $request->validated(), $request->file('proof'), $request
        ), 201);
    }

    public function voidPayment(
        Request $request, int $case, int $plan, int $payment
    ): JsonResponse {
        return $this->respond($this->service->voidPayment(
            $case, $plan, $payment, $this->reason($request), $request
        ));
    }

    public function attachProof(
        Request $request, int $case, int $plan, int $payment
    ): JsonResponse {
        $this->operations->restrict($request, ['proof']);
        $request->validate([
            'proof' => ['required', 'file', 'max:10240',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ]);
        return $this->respond($this->service->attachProof(
            $case, $plan, $payment, $request->file('proof'), $request
        ));
    }

    public function proof(int $case, int $plan, int $payment): BinaryFileResponse
    {
        return $this->service->proof($case, $plan, $payment);
    }

    public function events(int $case, int $plan): JsonResponse
    {
        return $this->respond($this->service->events($case, $plan));
    }

    private function reason(Request $request): string
    {
        $this->operations->restrict($request, ['reason']);
        $reason = trim($request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ])['reason']);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Informe um motivo detalhado.']);
        }
        return $reason;
    }

    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
