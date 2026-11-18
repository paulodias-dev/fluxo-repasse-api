<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDirectExternalTransferReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // JWT e sessão vigente, cases.manage na rota. Sem alçada financeira.
        return true;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'payer_party_id' => ['required', 'integer', 'min:1'],
            'beneficiary_party_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'transferred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'operator_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'confirmed_external' => ['required', 'accepted'],
            'proof' => ['required', 'file', 'max:10240',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ];
    }
}
