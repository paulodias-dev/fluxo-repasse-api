<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DistributionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Both permissions are enforced in the finance route group.
    }

    public function rules(): array
    {
        return [
            'template_revision' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:30'],
            'rows.*' => ['required', 'array:client_reference,title,gross_amount_cents,description'],
            'rows.*.client_reference' => ['required', 'uuid', 'distinct:ignore_case'],
            'rows.*.title' => ['required', 'string', 'min:2', 'max:180'],
            'rows.*.gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'rows.*.description' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['template_revision', 'request_key', 'rows'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'Campo não permitido.');
            }
        });
    }
}
