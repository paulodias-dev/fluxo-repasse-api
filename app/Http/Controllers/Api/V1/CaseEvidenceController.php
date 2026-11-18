<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDatabase;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $query = TenantDatabase::table('repasse_case_evidence')->where('repasse_case_id', $case);
        if (! ($filters['include_archived'] ?? false)) $query->whereNull('archived_at');
        $documents = $query->select(['id', 'original_name', 'mime', 'size_bytes', 'sha256',
            'uploaded_by', 'archived_at', 'created_at'])->orderByDesc('id')->paginate(30);
        return response()->json($documents)->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['file']);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $file = $data['file'];
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $original = mb_substr(trim(preg_replace('/[\\x00-\\x1F\\x7F]/u', '', basename($name))), 0, 180);
        if ($original === '') $original = 'documento';
        $sha = hash_file('sha256', $file->getRealPath());
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
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
            DB::transaction(function () use ($case, $request, $file, $path, $original, $sha, &$id): void {
                $this->operations->editable($case);
                if (! Storage::disk('local')->put($path, fopen($file->getRealPath(), 'rb'))) {
                    throw ValidationException::withMessages(['file' => 'Não foi possível guardar o documento.']);
                }
                $id = TenantDatabase::table('repasse_case_evidence')->insertGetId([
                    'repasse_case_id' => $case, 'original_name' => $original,
                    'storage_path' => $path, 'mime' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(), 'sha256' => $sha,
                    'uploaded_by' => $request->user('api')->getAuthIdentifier(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
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
        $row = TenantDatabase::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->where('id', $evidence)
            ->whereNull('archived_at')->firstOrFail();
        if (! Storage::disk('local')->exists($row->storage_path)) abort(404);
        // Sempre como anexo, nunca executar/mostrar HTML ou SVG.
        $name = Str::ascii($row->original_name);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        return Storage::disk('local')->download($row->storage_path, $name ?: 'documento', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archive(Request $request, int $case, int $evidence): JsonResponse
    {
        DB::transaction(function () use ($request, $case, $evidence): void {
            $this->operations->editable($case);
            $row = TenantDatabase::table('repasse_case_evidence')->where('repasse_case_id', $case)
                ->where('id', $evidence)->lockForUpdate()->firstOrFail();
            if ($row->archived_at !== null) return;
            TenantDatabase::table('repasse_case_evidence')->where('id', $evidence)
                ->update(['archived_at' => now(), 'updated_at' => now()]);
            $this->operations->event($case, $request, 'evidence_archived');
        });
        return response()->json(['data' => $this->metadata($case, $evidence)])
            ->header('Cache-Control', 'no-store');
    }

    private function metadata(int $case, int $id): object
    {
        return TenantDatabase::table('repasse_case_evidence')->where('repasse_case_id', $case)
            ->where('id', $id)->select(['id', 'original_name', 'mime', 'size_bytes',
                'sha256', 'uploaded_by', 'archived_at', 'created_at'])->firstOrFail();
    }
}
