<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveDistributionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // JWT, cases.manage and scenarios.manage are enforced by routes.
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        return [
            'case_id' => [$create ? 'required' : 'prohibited', 'integer', Rule::exists('repasse_cases', 'id')],
            'revision' => [$create ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['present', 'nullable', 'string', 'max:4000'],
            'source_party_id' => ['required', 'integer', Rule::exists('parties', 'id')],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['required', 'array:party_id,basis_points,memo'],
            'lines.*.party_id' => ['required', 'integer', 'distinct', Rule::exists('parties', 'id')],
            'lines.*.basis_points' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = $this->isMethod('POST')
                ? ['case_id', 'name', 'description', 'source_party_id', 'lines']
                : ['revision', 'name', 'description', 'source_party_id', 'lines'];
            foreach (array_diff(array_keys($this->all()), $fields) as $key) {
                $validator->errors()->add($key, 'Campo não permitido.');
            }
            $sum = 0;
            $lines = $this->input('lines', []);
            foreach (is_array($lines) ? $lines : [] as $line) {
                if (is_array($line) && is_int($line['basis_points'] ?? null)) {
                    $sum += $line['basis_points'];
                }
            }
            if ($sum !== 10000) {
                $validator->errors()->add('lines', 'Os pesos informados precisam totalizar 100,00%.');
            }
        });
    }
}
