<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // JWT + cases.manage + scenarios.manage nas rotas
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'case_id' => [$create ? 'required' : 'prohibited', 'integer', Rule::exists('repasse_cases', 'id')],
            'client_reference' => [$create ? 'required' : 'prohibited', 'uuid'],
            'revision' => [$create ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['present', 'nullable', 'string', 'max:4000'],
            'source_party_id' => ['present', 'nullable', 'integer', Rule::exists('parties', 'id')],
            'gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'allocations' => ['required', 'array', 'max:50'],
            'allocations.*' => ['required', 'array:party_id,amount_cents,memo'],
            'allocations.*.party_id' => ['required', 'integer', 'distinct', Rule::exists('parties', 'id')],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'allocations.*.memo' => ['sometimes', 'nullable', 'string', 'max:240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->isMethod('POST')
                ? ['case_id', 'client_reference', 'title', 'description', 'source_party_id', 'gross_amount_cents', 'allocations']
                : ['revision', 'title', 'description', 'source_party_id', 'gross_amount_cents', 'allocations'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'Campo não permitido neste módulo de simulação.');
            }
        });
    }
}
