<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ControlledRepasseService;
use App\Services\RepasseFinancialOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ControlledRepasseController extends Controller
{
    public function __construct(
        private readonly ControlledRepasseService $service,
        private readonly RepasseFinancialOverview $overview,
    ) {}

    private function only(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => 'Campos não reconhecidos: '.implode(', ', $unknown),
            ]);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    public function overview(int $case): JsonResponse
    {
        return $this->json(['data' => $this->overview->forVehicle($case)]);
    }

    public function options(int $case): JsonResponse
    {
        return $this->json(['data' => $this->service->options($case)]);
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $this->only($request, ['page']);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->service->index($case));
    }

    public function show(int $case, int $settlement): JsonResponse
    {
        return $this->json(['data' => $this->service->show($case, $settlement)]);
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $this->only($request, ['request_key', 'payer_party_id', 'base_amount_cents', 'memo', 'lines']);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'],
            'payer_party_id' => ['required', 'integer', 'min:1'],
            'base_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*' => ['required', 'array:beneficiary_party_id,kind,mode,amount_cents,basis_points'],
            'lines.*.beneficiary_party_id' => ['required', 'integer', 'min:1'],
            'lines.*.kind' => ['required', 'in:repasse,commission,tax,fee'],
            'lines.*.mode' => ['required', 'in:fixed,percentage'],
            'lines.*.amount_cents' => ['sometimes', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.basis_points' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $result = $this->service->create($case, $input, (int) $request->user('api')->id);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function approve(Request $request, int $case, int $settlement): JsonResponse
    {
        $this->only($request, ['confirm']);
        $request->validate(['confirm' => ['required', 'accepted']]);
        return $this->json(['data' => $this->service->approve(
            $case, $settlement, (int) $request->user('api')->id
        )]);
    }

    public function cancel(Request $request, int $case, int $settlement): JsonResponse
    {
        $this->only($request, ['reason']);
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $actor = $request->user('api');

        return $this->json(['data' => $this->service->cancel(
            $case, $settlement, $input['reason'], (int) $actor->id,
            $actor->hasPermission('repasse.approve'),
        )]);
    }

    public function pay(Request $request, int $case, int $settlement): JsonResponse
    {
        $this->only($request, [
            'request_key', 'line_id', 'amount_cents', 'paid_on',
            'external_reference', 'confirmed_external', 'proof',
        ]);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'],
            'line_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'confirmed_external' => ['required', 'accepted'],
            'proof' => ['nullable', 'file', 'max:10240',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ]);
        $result = $this->service->pay(
            $case, $settlement, $input, (int) $request->user('api')->id, $request->file('proof')
        );
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function voidPayment(Request $request, int $case, int $settlement, int $payment): JsonResponse
    {
        $this->only($request, ['reason']);
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        return $this->json(['data' => $this->service->voidPayment(
            $case, $settlement, $payment, $input['reason'], (int) $request->user('api')->id
        )]);
    }

    public function proof(int $case, int $settlement, int $payment): BinaryFileResponse
    {
        return $this->service->proof($case, $settlement, $payment);
    }
}
