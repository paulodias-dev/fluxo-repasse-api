<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StorePartyRequest extends FormRequest
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
            'type' => ['required', Rule::in(['person', 'company'])],
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
