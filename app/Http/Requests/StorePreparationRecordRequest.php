<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePreparationRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização contextual fica nas rotas: JWT + sessão corrente
        // + cases.manage E scenarios.manage; serviço confirma veículo/minuta.
        return true;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'expected_snapshot_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'adjustments' => ['required', 'array', 'max:20'],
            'adjustments.*' => ['required', 'array:kind,label,amount_cents'],
            'adjustments.*.kind' => ['required', Rule::in(['addition', 'deduction'])],
            'adjustments.*.label' => ['required', 'string', 'min:2', 'max:100'],
            'adjustments.*.amount_cents' => [
                'required', 'integer', 'min:1', 'max:1000000000000',
            ],
            'replaces_record_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
