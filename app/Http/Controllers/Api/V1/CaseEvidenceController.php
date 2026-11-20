<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use App\Support\TenantDB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CaseEvidenceController extends Controller
{
    public function __construct(private readonly CaseOperations $operations) {}

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'include_archived' => ['sometimes', 'boolean'],
        ]);
        $query = TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case);
        if (! ($filters['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }
        $documents = $query->select(['id', 'original_name', 'mime', 'size_bytes', 'sha256',
            'uploaded_by', 'document_type', 'document_label', 'is_cover', 'archived_at', 'created_at'])
            ->orderByDesc('is_cover')->orderByDesc('id')->paginate(30);

        return response()->json($documents)->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['file', 'document_type', 'document_label']);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'document_type' => ['required', \Illuminate\Validation\Rule::in([
                'foto_veiculo', 'crlv', 'crv_dut', 'ipva', 'laudo_cautelar',
                'laudo_tecnico', 'laudo_concessionaria', 'nota_fiscal', 'contrato', 'outros',
            ])],
            'document_label' => ['required_if:document_type,outros', 'nullable', 'string', 'min:2', 'max:120'],
        ]);
        $file = $data['file'];
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $original = mb_substr(trim(preg_replace('/[\\x00-\\x1F\\x7F]/u', '', basename($name))), 0, 180);
        if ($original === '') {
            $original = 'documento';
        }
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }
        $mime = $file->getMimeType();
        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            try {
                $encoded = ImageManager::gd()->read($file->getRealPath())
                    ->scaleDown(width: 1920, height: 1920)
                    ->toWebp(quality: 82);
                $contents = (string) $encoded;
                $mime = 'image/webp';
                $original = pathinfo($original, PATHINFO_FILENAME).'.webp';
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'file' => 'Não foi possível processar a imagem enviada.',
                ]);
            }
        }
        $sha = hash('sha256', $contents);
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/jpeg', 'image/png', 'image/webp' => 'webp',
            default => null,
        };
        if (! $extension) {
            throw ValidationException::withMessages(['file' => 'Formato não autorizado.']);
        }
        $directory = "repasse-evidence/{$case}";
        $path = $directory.'/'.Str::uuid().'.'.$extension;

        // O disco local Laravel é privado: nunca oferecer URL pública do arquivo.
        $id = null;
        try {
            DB::transaction(function () use ($case, $request, $data, $contents, $path, $original, $mime, $sha, &$id): void {
                $this->operations->editable($case);
                if (! Storage::disk('local')->put($path, $contents)) {
                    throw ValidationException::withMessages(['file' => 'Não foi possível guardar o documento.']);
                }
                $id = TenantDB::table('repasse_case_evidence')->insertGetId([
                    'repasse_case_id' => $case, 'original_name' => $original,
                    'storage_path' => $path, 'mime' => $mime,
                    'size_bytes' => strlen($contents), 'sha256' => $sha,
                    'uploaded_by' => $request->user('api')->getAuthIdentifier(),
                    'document_type' => $data['document_type'],
                    'document_label' => isset($data['document_label']) ? trim((string) $data['document_label']) : null,
                    'is_cover' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($data['document_type'] === 'foto_veiculo' && ! TenantDB::table('repasse_case_evidence')
                    ->where('repasse_case_id', $case)->where('document_type', 'foto_veiculo')
                    ->where('mime', 'like', 'image/%')->whereNull('archived_at')
                    ->where('is_cover', true)->exists()) {
                    TenantDB::table('repasse_case_evidence')->where('id', $id)->update(['is_cover' => true]);
                }
                $this->operations->event($case, $request, 'evidence_uploaded');
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['data' => $this->metadata($case, $id)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function download(int $case, int $evidence): StreamedResponse
    {
        $this->operations->ensureExists($case);
        $row = TenantDB::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->where('id', $evidence)
            ->whereNull('archived_at')->firstOrFail();
        if (! Storage::disk('local')->exists($row->storage_path)) {
            abort(404);
        }
        // Sempre como anexo, nunca executar/mostrar HTML ou SVG.
        $name = Str::ascii($row->original_name);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        return Storage::disk('local')->download($row->storage_path, $name ?: 'documento', [
            'Content-Type' => str_starts_with((string) $row->mime, 'image/')
                ? $row->mime : 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, int $case, int $evidence): JsonResponse
    {
        $this->operations->restrict($request, ['document_type', 'document_label', 'is_cover']);
        $data = $request->validate([
            'document_type' => ['sometimes', Rule::in([
                'foto_veiculo', 'crlv', 'crv_dut', 'ipva', 'laudo_cautelar',
                'laudo_tecnico', 'laudo_concessionaria', 'nota_fiscal', 'contrato', 'outros',
            ])],
            'document_label' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'is_cover' => ['sometimes', 'boolean'],
        ]);

        $metadata = DB::transaction(function () use ($request, $case, $evidence, $data): object {
            $this->operations->editable($case);
            $row = TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
                ->where('id', $evidence)->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            $nextType = $data['document_type'] ?? $row->document_type;
            $isImage = str_starts_with((string) $row->mime, 'image/');
            if (($data['is_cover'] ?? false) && (! $isImage || $nextType !== 'foto_veiculo')) {
                throw ValidationException::withMessages(['is_cover' => 'Somente fotos do veículo podem ser a foto principal.']);
            }
            if (($data['is_cover'] ?? false)) {
                TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
                    ->where('id', '!=', $evidence)->where('mime', 'like', 'image/%')
                    ->update(['is_cover' => false, 'updated_at' => now()]);
            }
            $updates = [];
            if (array_key_exists('document_type', $data)) $updates['document_type'] = $data['document_type'];
            if (array_key_exists('document_label', $data)) {
                $updates['document_label'] = $data['document_label'] ? trim($data['document_label']) : null;
            }
            if (array_key_exists('is_cover', $data)) $updates['is_cover'] = (bool) $data['is_cover'];
            if ($nextType !== 'foto_veiculo') $updates['is_cover'] = false;
            $updates['updated_at'] = now();
            TenantDB::table('repasse_case_evidence')->where('id', $evidence)->update($updates);
            $this->operations->event($case, $request, 'evidence_updated');

            return $this->metadata($case, $evidence);
        });

        return response()->json(['data' => $metadata])->header('Cache-Control', 'no-store');
    }

    public function replace(Request $request, int $case, int $evidence): JsonResponse
    {
        $this->operations->restrict($request, ['file']);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ]);
        $this->operations->ensureExists($case);
        $row = TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
            ->where('id', $evidence)->whereNull('archived_at')->firstOrFail();
        if (! str_starts_with((string) $row->mime, 'image/')) {
            throw ValidationException::withMessages(['file' => 'Somente fotos podem ser substituídas por esta ação.']);
        }

        $prepared = $this->prepareImage($data['file']);
        $path = "repasse-evidence/{$case}/".Str::uuid().'.webp';
        $newId = null;
        try {
            DB::transaction(function () use ($request, $case, $evidence, $row, $prepared, $path, &$newId): void {
                $this->operations->editable($case);
                if (! Storage::disk('local')->put($path, $prepared['contents'])) {
                    throw ValidationException::withMessages(['file' => 'Não foi possível guardar a nova foto.']);
                }
                $newId = TenantDB::table('repasse_case_evidence')->insertGetId([
                    'repasse_case_id' => $case, 'original_name' => $prepared['original'],
                    'storage_path' => $path, 'mime' => 'image/webp',
                    'size_bytes' => strlen($prepared['contents']), 'sha256' => hash('sha256', $prepared['contents']),
                    'uploaded_by' => $request->user('api')->getAuthIdentifier(),
                    'document_type' => 'foto_veiculo', 'document_label' => $row->document_label,
                    'is_cover' => (bool) $row->is_cover,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                TenantDB::table('repasse_case_evidence')->where('id', $evidence)
                    ->update(['archived_at' => now(), 'is_cover' => false, 'updated_at' => now()]);
                $this->operations->event($case, $request, 'evidence_replaced');
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['data' => $this->metadata($case, $newId)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function archive(Request $request, int $case, int $evidence): JsonResponse
    {
        DB::transaction(function () use ($request, $case, $evidence): void {
            $this->operations->editable($case);
            $row = TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
                ->where('id', $evidence)->lockForUpdate()->firstOrFail();
            if ($row->archived_at !== null) {
                return;
            }
            TenantDB::table('repasse_case_evidence')->where('id', $evidence)
                ->update(['archived_at' => now(), 'updated_at' => now()]);
            $this->operations->event($case, $request, 'evidence_archived');
        });

        return response()->json(['data' => $this->metadata($case, $evidence)])
            ->header('Cache-Control', 'no-store');
    }

    private function metadata(int $case, int $id): object
    {
        return TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
            ->where('id', $id)->select(['id', 'original_name', 'mime', 'size_bytes',
                'sha256', 'uploaded_by', 'document_type', 'document_label', 'is_cover', 'archived_at', 'created_at'])->firstOrFail();
    }

    /** @return array{contents: string, original: string} */
    private function prepareImage(UploadedFile $file): array
    {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $original = mb_substr(trim((string) preg_replace('/[\\x00-\\x1F\\x7F]/u', '', basename($name))), 0, 180);
        if ($original === '') $original = 'foto-veiculo';
        try {
            $contents = (string) ImageManager::gd()->read($file->getRealPath())
                ->scaleDown(width: 1920, height: 1920)->toWebp(quality: 82);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Não foi possível processar a imagem enviada.']);
        }
        return ['contents' => $contents, 'original' => pathinfo($original, PATHINFO_FILENAME).'.webp'];
    }
}
