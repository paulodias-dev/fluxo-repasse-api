<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RealizeVehicleFinanceEntryRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route's JWT, current session and
     * cases.manage middleware. No approval/payment grant is implied.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_on' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.now()->toDateString(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_on.required' => 'Informe a data em que o lançamento ocorreu.',
            'effective_on.date_format' => 'Informe uma data válida no formato AAAA-MM-DD.',
            'effective_on.before_or_equal' => 'A data realizada não pode estar no futuro.',
        ];
    }
}
