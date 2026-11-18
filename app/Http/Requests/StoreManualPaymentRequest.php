<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreManualPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'beneficiary_party_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'proof' => ['sometimes', 'nullable', 'file', 'max:10240',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ];
    }
}
