<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
final class AccountDeletionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted'], 'user_id' => ['prohibited'], 'tenant_id' => ['prohibited']];
    }
}
