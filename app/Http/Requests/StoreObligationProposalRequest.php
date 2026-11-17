<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreObligationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // JWT, sessão e AMBAS permissões técnicas são aplicadas nas rotas.
        return true;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'preparation_record_id' => ['required', 'integer', 'min:1'],
            'expected_record_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'payer_party_id' => ['required', 'integer', 'min:1'],
            'trigger_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'operator_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
