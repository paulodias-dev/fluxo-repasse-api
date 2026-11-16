<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdatePartyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'email', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        if (isset($normalized['email'])) {
            $normalized['email'] = Str::lower($normalized['email']);
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(['person', 'company'])],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
            'bank_account' => ['prohibited'],
            'pix_key' => ['prohibited'],
            'amount' => ['prohibited'],
            'commission' => ['prohibited'],
        ];
    }
}
