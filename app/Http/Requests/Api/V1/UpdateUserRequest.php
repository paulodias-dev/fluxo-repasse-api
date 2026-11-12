<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:254',
                Rule::unique('users', 'email')->ignore($this->route('user')?->getKey())],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
            'password' => ['prohibited'],
            'is_active' => ['prohibited'],
            'auth_version' => ['prohibited'],
        ];
    }
}
