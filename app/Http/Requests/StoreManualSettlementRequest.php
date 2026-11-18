<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreManualSettlementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'payer_party_id' => ['required', 'integer', 'min:1'],
            'gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'mode' => ['required', Rule::in(['fixed', 'percentage'])],
            'deductions' => ['sometimes', 'array', 'max:20'],
            'deductions.*' => ['array:kind,description,amount_cents'],
            'deductions.*.kind' => ['required', Rule::in(['fee', 'tax'])],
            'deductions.*.description' => ['required', 'string', 'min:2', 'max:180'],
            'deductions.*.amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:party_id,kind,memo,amount_cents,basis_points'],
            'lines.*.party_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.kind' => ['required', Rule::in(['share', 'commission'])],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:240'],
            'lines.*.basis_points' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'lines.*.amount_cents' => ['sometimes', 'integer', 'min:1', 'max:1000000000000'],
        ];
    }
}
