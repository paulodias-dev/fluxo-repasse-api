<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachVehicleFinanceDocumentRequest;
use App\Services\CaseOperations;
use App\Services\MediaStorage;
use App\Services\VehicleFinanceWorkspace;
use App\Support\TenantDB;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class VehicleFinanceDocumentController extends Controller
{
    public function __construct(
        private readonly CaseOperations $operations,
        private readonly VehicleFinanceWorkspace $finance,
        private readonly MediaStorage $media,
    ) {}

    public function attach(AttachVehicleFinanceDocumentRequest $request, int $case, int $entry): JsonResponse
    {
        $this->operations->restrict($request, ['file', 'evidence_id']);
        $data = $request->validated();

        $file = $data['file'] ?? null;
        $path = null;
        $original = null;
        $mime = null;
        $sha = null;
        $extension = null;
        if ($file) {
            $original = str_replace('\\', '/', $file->getClientOriginalName());
            $original = mb_substr(trim(preg_replace(
                '/[\x00-\x1F\x7F]/u', '', basename($original)
            )), 0, 180);
            if ($original === '') {
                $original = 'comprovante';
            }
            $mime = $file->getMimeType();
            $extension = match ($mime) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };
            if (! $extension) {
                throw ValidationException::withMessages(['file' => 'Formato de comprovante não permitido.']);
            }
            $sha = hash_file('sha256', $file->getRealPath());
            $path = "repasse-evidence/{$case}/".Str::uuid().'.'.$extension;
        }

        try {
            $created = DB::transaction(function () use (
                $request, $case, $entry, $data, $file, $path, $original, $mime, $sha
            ): bool {
                $this->operations->editable($case);
                $item = TenantDB::table('repasse_case_financial_entries')
                    ->where('repasse_case_id', $case)->where('id', $entry)
                    ->lockForUpdate()->firstOrFail();
                if ($item->voided_at !== null) {
                    throw ValidationException::withMessages([
                        'entry' => 'Não é possível anexar comprovantes a lançamento estornado.',
                    ]);
                }

                // Duplo toque ou reenvio da mesma foto ao MESMO lançamento:
                // retorna o vínculo atual, sem nova cópia e sem novo evento.
                // Lock no lançamento serializa uploads concorrentes deste ID.
                if ($file) {
                    $alreadyLinked = TenantDB::table('repasse_case_financial_documents as link')
                        ->join('repasse_case_evidence as evidence', 'evidence.id', '=', 'link.evidence_id')
                        ->where('link.repasse_case_id', $case)
                        ->where('link.entry_id', $entry)
                        ->where('evidence.sha256', $sha)
                        ->whereNull('evidence.archived_at')
                        ->select('evidence.storage_path', 'evidence.sha256', 'evidence.size_bytes')
                        ->first();
                    if ($alreadyLinked) {
                        if (! $this->media->verify(
                            $alreadyLinked->storage_path,
                            (int) $alreadyLinked->size_bytes,
                            $alreadyLinked->sha256
                        )) {
                            abort(409, 'Comprovante anterior indisponível ou com integridade divergente.');
                        }

                        return false;
                    }
                }

                if ($file) {
                    $resource = fopen($file->getRealPath(), 'rb');
                    try {
                        $this->media->putStream($path, $resource);
                    } finally {
                        if (is_resource($resource)) {
                            fclose($resource);
                        }
                    }
                    $evidence = TenantDB::table('repasse_case_evidence')->insertGetId([
                        'repasse_case_id' => $case, 'original_name' => $original,
                        'storage_path' => $path, 'mime' => $mime,
                        'size_bytes' => $file->getSize(), 'sha256' => $sha,
                        'uploaded_by' => $request->user('api')->getAuthIdentifier(),
                        'document_type' => 'comprovante_financeiro',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $evidence = (int) $data['evidence_id'];
                    $document = TenantDB::table('repasse_case_evidence')
                        ->where('id', $evidence)->where('repasse_case_id', $case)
                        ->lockForUpdate()->first();
                    if (! $document || $document->archived_at !== null) {
                        throw ValidationException::withMessages([
                            'evidence_id' => 'Documento não localizado ou arquivado neste veículo.',
                        ]);
                    }
                    $existing = TenantDB::table('repasse_case_financial_documents')
                        ->where('evidence_id', $evidence)->first();
                    if ($existing) {
                        if ((int) $existing->entry_id === $entry &&
                            (int) $existing->repasse_case_id === $case) {
                            return false;
                        }
                        throw ValidationException::withMessages([
                            'evidence_id' => 'Documento já vinculado a outro lançamento.',
                        ]);
                    }
                }

                TenantDB::table('repasse_case_financial_documents')->insert([
                    'repasse_case_id' => $case, 'entry_id' => $entry,
                    'evidence_id' => $evidence,
                    'linked_by' => $request->user('api')->getAuthIdentifier(),
                    'created_at' => now(),
                ]);
                $this->finance->event($case, $entry, $request, 'document_attached', [
                    'evidence_id' => $evidence, 'uploaded' => (bool) $file,
                ]);
                $this->operations->event($case, $request, 'vehicle_fin_document_attached');

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            if ($path) {
                $this->media->delete($path);
            }
            throw ValidationException::withMessages([
                'evidence_id' => 'Documento já vinculado. Atualize a lista.',
            ]);
        } catch (Throwable $exception) {
            if ($path) {
                $this->media->delete($path);
            }
            throw $exception;
        }

        return response()->json([
            'data' => $this->finance->withDocuments($case, $entry),
            'created' => $created,
        ], $created ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function detach(Request $request, int $case, int $entry, int $evidence): JsonResponse
    {
        $this->operations->restrict($request, []);
        DB::transaction(function () use ($request, $case, $entry, $evidence): void {
            $this->operations->editable($case);
            $record = TenantDB::table('repasse_case_financial_entries')
                ->where('repasse_case_id', $case)->where('id', $entry)
                ->lockForUpdate()->firstOrFail();
            if ($record->voided_at !== null) {
                throw ValidationException::withMessages([
                    'entry' => 'O lançamento estornado não pode ser alterado.',
                ]);
            }
            $link = TenantDB::table('repasse_case_financial_documents')
                ->where('repasse_case_id', $case)->where('entry_id', $entry)
                ->where('evidence_id', $evidence)->lockForUpdate()->firstOrFail();
            TenantDB::table('repasse_case_financial_documents')->where('id', $link->id)->delete();
            $this->finance->event($case, $entry, $request, 'document_detached', [
                'evidence_id' => $evidence,
            ]);
            $this->operations->event($case, $request, 'vehicle_fin_document_detached');
        });

        // Original document and its hash remain in the vehicle private library.
        return response()->json(['data' => $this->finance->withDocuments($case, $entry)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(int $case, int $entry, int $evidence): StreamedResponse
    {
        $this->operations->ensureExists($case);
        $this->finance->entry($case, $entry);
        $document = TenantDB::table('repasse_case_financial_documents as l')
            ->join('repasse_case_evidence as e', 'e.id', '=', 'l.evidence_id')
            ->where('l.repasse_case_id', $case)->where('l.entry_id', $entry)
            ->where('l.evidence_id', $evidence)->whereNull('e.archived_at')
            ->select('e.storage_path', 'e.original_name', 'e.sha256', 'e.size_bytes')->firstOrFail();
        if (! $this->media->verify($document->storage_path, (int) $document->size_bytes, $document->sha256)) {
            abort(409, 'Integridade do comprovante divergente.');
        }

        $name = Str::ascii($document->original_name);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        return $this->media->download($document->storage_path, $name ?: 'comprovante', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
