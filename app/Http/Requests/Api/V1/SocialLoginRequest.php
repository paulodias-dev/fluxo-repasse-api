<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class SocialLoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $provider = $this->input('provider');
        $intent = $this->input('intent');

        $normalized = [];
        if (is_string($provider)) {
            $normalized['provider'] = Str::lower(trim($provider));
        }
        if (is_string($intent)) {
            $normalized['intent'] = Str::lower(trim($intent));
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:google'],
            'access_token' => ['required', 'string', 'min:20', 'max:4096'],
            'intent' => ['required', 'string', 'in:login,register'],
            'company_name' => ['required_if:intent,register', 'nullable', 'string', 'min:2', 'max:180'],
            'terms_accepted' => ['required_if:intent,register', 'accepted'],
        ];
    }
}
