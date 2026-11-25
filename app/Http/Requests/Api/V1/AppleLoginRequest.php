<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class AppleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identity_token' => ['required', 'string', 'max:8192'],
            'authorization_code' => [config('services.apple_native.exchange_required') ? 'required' : 'sometimes', 'string', 'max:4096'],
            'nonce' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'intent' => ['required', 'in:login,register'],
            'terms_accepted' => ['exclude_unless:intent,register', 'required', 'accepted'],
            'name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'email' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
        ];
    }
}
