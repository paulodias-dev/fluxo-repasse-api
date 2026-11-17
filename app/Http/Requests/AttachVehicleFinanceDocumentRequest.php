<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AttachVehicleFinanceDocumentRequest extends FormRequest
{
    /**
     * Acesso e escrita são protegidos pela rota: JWT bearer, sessão vigente,
     * cases.manage e permissão de editar o veículo no serviço operacional.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required_without:evidence_id', 'prohibited_with:evidence_id',
                'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp',
            ],
            'evidence_id' => [
                'required_without:file', 'prohibited_with:file', 'integer', 'min:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'O comprovante deve ter no máximo 10 MB.',
            'file.mimes' => 'Use PDF ou imagem JPEG, PNG ou WebP. Arquivos HEIC não são aceitos.',
            'file.required_without' => 'Escolha uma foto, PDF ou documento já cadastrado.',
            'evidence_id.required_without' => 'Escolha uma foto, PDF ou documento já cadastrado.',
            'file.prohibited_with' => 'Envie um arquivo ou vincule um existente, nunca ambos.',
            'evidence_id.prohibited_with' => 'Envie um arquivo ou vincule um existente, nunca ambos.',
        ];
    }
}
