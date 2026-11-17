<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VehiclePortfolioIndexRequest extends FormRequest
{
    /**
     * JWT, session.current e cases.manage permanecem no grupo da rota.
     * FormRequest valida somente filtros de leitura, sem privilégio extra.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'attention' => ['sometimes', Rule::in([
                'all', 'overdue', 'open_tasks', 'missing_documents',
                'incomplete_profile', 'planned_expenses',
            ])],
            'sort' => ['sometimes', Rule::in([
                'updated', 'oldest', 'purchase_desc', 'overdue', 'priority',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
