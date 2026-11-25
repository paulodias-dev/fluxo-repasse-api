<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NativeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\\[[A-Za-z0-9_-]+\\]$/'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
