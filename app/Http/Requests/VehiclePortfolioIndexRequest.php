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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'sold', 'archived'])],
            'attention' => ['sometimes', Rule::in([
                'all', 'overdue', 'open_tasks', 'missing_documents',
                'incomplete_profile', 'planned_expenses',
            ])],
            'performance' => ['sometimes', Rule::in([
                'all', 'sold_profit', 'sold_loss', 'sold_break_even',
                'sold_unknown', 'not_sold', 'purchase_missing',
            ])],
            'sort' => ['sometimes', Rule::in([
                'updated', 'oldest', 'purchase_desc', 'overdue', 'priority',
                'sale_result_desc', 'sale_result_asc',
            ])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
