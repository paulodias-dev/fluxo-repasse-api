<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CollaborationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SharedVehicleController extends Controller
{
    public function __construct(private readonly CollaborationAccess $access) {}

    private function vehicle(object $grant): object
    {
        return DB::table('repasse_cases')->where('id', $grant->vehicle_id)
            ->where('tenant_id', $grant->owner_tenant_id)->firstOrFail();
    }

    private function financialRows(object $grant): array
    {
        $query = DB::table('repasse_case_financial_entries as e')
            ->leftJoin('users as author', 'author.id', '=', 'e.created_by')
            ->leftJoin('users as payer', 'payer.id', '=', 'e.paid_by_user_id')
            ->where('e.tenant_id', $grant->owner_tenant_id)
            ->where('e.repasse_case_id', $grant->vehicle_id)->whereNull('e.voided_at');
        if (! $grant->include_history) $query->where('e.id', '>', $grant->entry_floor_id);

        return $query->orderByDesc('e.id')->limit(100)
            ->get(['e.id', 'e.kind', 'e.status', 'e.category', 'e.description',
                'e.amount_cents', 'e.effective_on', 'e.created_at', 'e.paid_by_user_id',
                'author.name as author_name', 'payer.name as payer_name'])->all();
    }

    public function show(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->access->grant($request, $grantId);
        $vehicle = $this->vehicle($grant);
        $photo = DB::table('repasse_case_evidence')->where('tenant_id', $grant->owner_tenant_id)
            ->where('repasse_case_id', $grant->vehicle_id)
            ->where('document_type', 'foto_veiculo')->whereNull('archived_at')
            ->orderByDesc('is_cover')->orderByDesc('id')->value('id');
        $chat = DB::table('collab_conversations')->where('vehicle_id', $grant->vehicle_id)
            ->where('owner_tenant_id', $grant->owner_tenant_id)->value('id');
        $participants = DB::table('users')->whereIn('id', array_unique(array_merge(
            [(int) $vehicle->created_by],
            DB::table('vehicle_access_grants')
                ->where('vehicle_id', $vehicle->id)->where('owner_tenant_id', $grant->owner_tenant_id)
                ->where('status', 'active')->whereNull('revoked_at')->pluck('recipient_id')->all()
        )))->where('is_active', true)->get(['id', 'name']);
        return response()->json(['data' => [
            'participants' => $participants,
            'id' => $grant->id, 'vehicle_id' => $vehicle->id,
            'title' => $vehicle->title, 'description' => $vehicle->description,
            'plate' => $vehicle->plate, 'chassis' => $vehicle->chassis,
            'brand' => $vehicle->brand, 'model' => $vehicle->model,
            'model_year' => $vehicle->model_year, 'vehicle_type' => $vehicle->vehicle_type,
            'vehicle_version' => $vehicle->vehicle_version,
            'can_edit' => (bool) $grant->allow_write, 'include_history' => (bool) $grant->include_history,
            'photo_id' => $photo, 'chat_id' => $chat,
            'entries' => $this->financialRows($grant),
        ]], 200, [], JSON_INVALID_UTF8_SUBSTITUTE)->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->access->grant($request, $grantId, true);
        $this->assertFields($request, ['title', 'description', 'brand', 'model',
            'model_year', 'vehicle_type', 'vehicle_version']);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_type' => ['sometimes', 'nullable', Rule::in(['cars', 'motorcycles', 'trucks'])],
            'vehicle_version' => ['sometimes', 'nullable', 'string', 'max:180'],
        ]);
        if ($data === []) throw ValidationException::withMessages(['vehicle' => 'Informe os campos a alterar.']);
        DB::transaction(function () use ($grant, $request, $data) {
            // A mesma linha é bloqueada pela revogação: nenhuma gravação é concluída
            // depois que o proprietário encerra a permissão.
            DB::table('vehicle_access_grants')->where('id', $grant->id)->lockForUpdate()->firstOrFail();
            $this->access->grant($request, (int) $grant->id, true);
            $vehicle = DB::table('repasse_cases')->where('id', $grant->vehicle_id)
                ->where('tenant_id', $grant->owner_tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless($vehicle->status === 'draft', 422);
            DB::table('repasse_cases')->where('id', $vehicle->id)->update([
                ...$data, 'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            $this->event($grant, $request, 'collaborator_profile_updated');
        });
        return $this->show($request, $grantId);
    }

    public function entry(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->access->grant($request, $grantId, true);
        $this->assertFields($request, ['kind', 'status', 'category', 'description',
            'amount_cents', 'effective_on', 'paid_by_user_id', 'request_key']);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['expense', 'income'])],
            'status' => ['required', Rule::in(['planned', 'realized'])],
            'category' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'paid_by_user_id' => ['required_if:status,realized', 'nullable', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
        ]);
        $actor = $this->access->actor($request);
        if ($data['status'] === 'planned' && ! empty($data['paid_by_user_id'])) {
            throw ValidationException::withMessages(['paid_by_user_id' => 'Despesa/receita prevista não possui pagador realizado.']);
        }
        $id = DB::transaction(function () use ($request, $grant, $actor, $data) {
            // A mesma linha é bloqueada pela revogação: nenhuma gravação é concluída
            // depois que o proprietário encerra a permissão.
            DB::table('vehicle_access_grants')->where('id', $grant->id)->lockForUpdate()->firstOrFail();
            $this->access->grant($request, (int) $grant->id, true);
            $case = DB::table('repasse_cases')->where('id', $grant->vehicle_id)
                ->where('tenant_id', $grant->owner_tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless($case->status === 'draft', 422);
            if (! empty($data['paid_by_user_id'])) {
                $this->access->financialPayer(
                    (int) $grant->vehicle_id, (int) $grant->owner_tenant_id,
                    (int) $data['paid_by_user_id']
                );
            }
            $previous = DB::table('repasse_case_financial_entries')
                ->where('tenant_id', $grant->owner_tenant_id)->where('repasse_case_id', $grant->vehicle_id)
                ->where('collaboration_request_key', $data['request_key'])->first();
            if ($previous) {
                if ((int) $previous->created_by !== (int) $actor->id || $previous->kind !== $data['kind'] ||
                    $previous->description !== $data['description'] ||
                    (int) $previous->amount_cents !== (int) $data['amount_cents'] ||
                    $previous->status !== $data['status'] ||
                    $previous->category !== trim($data['category']) ||
                    $previous->effective_on !== $data['effective_on'] ||
                    (int) ($previous->paid_by_user_id ?? 0) !== (int) ($data['paid_by_user_id'] ?? 0)) {
                    abort(409, 'Identificador de tentativa reutilizado com outros dados.');
                }
                return [(int) $previous->id, false];
            }
            $id = DB::table('repasse_case_financial_entries')->insertGetId([
                'tenant_id' => $grant->owner_tenant_id,
                'repasse_case_id' => $grant->vehicle_id, 'kind' => $data['kind'],
                'status' => $data['status'], 'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount_cents' => $data['amount_cents'], 'effective_on' => $data['effective_on'],
                'paid_by_user_id' => $data['paid_by_user_id'] ?? null,
                'collaboration_request_key' => $data['request_key'], 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repasse_case_financial_events')->insert([
                'tenant_id' => $grant->owner_tenant_id,
                'repasse_case_id' => $grant->vehicle_id, 'entry_id' => $id,
                'actor_user_id' => $actor->id, 'event_type' => 'collaborator_created',
                'details' => json_encode(['paid_by_user_id' => $data['paid_by_user_id'] ?? null],
                    JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $this->event($grant, $request, 'collaborator_finance_created');
            return [$id, true];
        }, 3);
        return response()->json(['data' => ['id' => $id[0], 'created' => $id[1]]],
            $id[1] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function documents(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->access->grant($request, $grantId);
        $query = DB::table('repasse_case_evidence')->where('tenant_id', $grant->owner_tenant_id)
            ->where('repasse_case_id', $grant->vehicle_id)->whereNull('archived_at');
        if (! $grant->include_history) $query->where('id', '>', $grant->document_floor_id);
        return response()->json(['data' => $query->orderByDesc('id')->limit(100)
            ->get(['id', 'original_name', 'document_type', 'document_label', 'mime',
                'size_bytes', 'created_at'])], 200, [], JSON_INVALID_UTF8_SUBSTITUTE)
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, int $grantId, int $document): StreamedResponse
    {
        $grant = $this->access->grant($request, $grantId);
        $file = DB::table('repasse_case_evidence')->where('tenant_id', $grant->owner_tenant_id)
            ->where('repasse_case_id', $grant->vehicle_id)->where('id', $document)
            ->whereNull('archived_at')->firstOrFail();
        if (! $grant->include_history && (int) $file->id <= (int) $grant->document_floor_id) {
            $cover = DB::table('repasse_case_evidence')
                ->where('tenant_id', $grant->owner_tenant_id)
                ->where('repasse_case_id', $grant->vehicle_id)
                ->where('document_type', 'foto_veiculo')->whereNull('archived_at')
                ->orderByDesc('is_cover')->orderByDesc('id')->value('id');
            abort_unless((int) $cover === (int) $file->id, 404);
        }
        return $this->privateFile($file);
    }

    public function addDocument(Request $request, int $grantId): JsonResponse
    {
        $grant = $this->access->grant($request, $grantId, true);
        $this->assertFields($request, ['file', 'document_type', 'document_label']);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'document_type' => ['required', Rule::in([
                'foto_veiculo','crlv','crv_dut','ipva','laudo_cautelar','laudo_tecnico',
                'laudo_concessionaria','nota_fiscal','contrato','outros',
            ])],
            'document_label' => ['required_if:document_type,outros', 'nullable','string','max:120'],
        ]);
        $file = $data['file'];
        $path = $file->store('repasse-evidence/'.$grant->vehicle_id, 'local');
        abort_unless($path, 503);
        try {
            $id = DB::transaction(function () use ($request, $grant, $data, $file, $path) {
                // A mesma linha é bloqueada pela revogação: nenhuma gravação é concluída
            // depois que o proprietário encerra a permissão.
            DB::table('vehicle_access_grants')->where('id', $grant->id)->lockForUpdate()->firstOrFail();
            $this->access->grant($request, (int) $grant->id, true);
                $case = DB::table('repasse_cases')->where('id', $grant->vehicle_id)
                    ->where('tenant_id', $grant->owner_tenant_id)->lockForUpdate()->firstOrFail();
                abort_unless($case->status === 'draft', 422);
                $id = DB::table('repasse_case_evidence')->insertGetId([
                    'tenant_id' => $grant->owner_tenant_id, 'repasse_case_id' => $grant->vehicle_id,
                    'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                    'storage_path' => $path, 'mime' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                    'document_type' => $data['document_type'],
                    'document_label' => $data['document_label'] ?? null,
                    'uploaded_by' => $request->user('api')->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($grant, $request, 'collaborator_document_uploaded');
                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function oldHistory(Request $request, int $transfer): JsonResponse
    {
        $actor = $this->access->actor($request);
        $t = DB::table('vehicle_ownership_transfers')->where('id', $transfer)
            ->where('recipient_id', $actor->id)
            ->where('target_tenant_id', $actor->tenant_id)->where('include_history', true)->firstOrFail();
        $old = DB::table('repasse_cases')->where('id', $t->source_vehicle_id)
            ->where('tenant_id', $t->source_tenant_id)->firstOrFail();
        $params = $request->validate([
            'before_entry' => ['sometimes', 'integer', 'min:1'],
            'before_document' => ['sometimes', 'integer', 'min:1'],
        ]);
        $entryQuery = DB::table('repasse_case_financial_entries')
            ->where('tenant_id', $t->source_tenant_id)->where('repasse_case_id', $old->id);
        if (isset($params['before_entry'])) {
            $entryQuery->where('id', '<', $params['before_entry']);
        }
        $entryPage = $entryQuery->orderByDesc('id')->limit(51)
            ->get(['id', 'kind', 'status', 'category', 'description',
                'amount_cents', 'effective_on', 'voided_at', 'created_by', 'paid_by_user_id']);
        $entries = $entryPage->take(50)->values();
        $documentsQuery = DB::table('repasse_case_evidence')
            ->where('tenant_id', $t->source_tenant_id)->where('repasse_case_id', $old->id)
            ->whereNull('archived_at');
        if (isset($params['before_document'])) {
            $documentsQuery->where('id', '<', $params['before_document']);
        }
        $documentPage = $documentsQuery->orderByDesc('id')->limit(51)
            ->get(['id', 'original_name', 'document_type', 'mime', 'size_bytes']);
        $documents = $documentPage->take(50)->values();
        return response()->json(['data' => [
            'title' => $old->title, 'plate' => $old->plate,
            'historical_purchase_amount_cents' => $old->purchase_amount_cents,
            'historical_sale_amount_cents' => $old->sale_amount_cents,
            'entries' => $entries, 'documents' => $documents,
            'entries_more' => $entryPage->count() > 50,
            'documents_more' => $documentPage->count() > 50,
            'entries_cursor' => $entries->last()?->id,
            'documents_cursor' => $documents->last()?->id,
        ]], 200, [], JSON_INVALID_UTF8_SUBSTITUTE)->header('Cache-Control', 'no-store, private');
    }

    public function oldDocument(Request $request, int $transfer, int $document): StreamedResponse
    {
        $actor = $this->access->actor($request);
        $t = DB::table('vehicle_ownership_transfers')->where('id', $transfer)
            ->where('recipient_id', $actor->id)->where('target_tenant_id', $actor->tenant_id)
            ->where('include_history', true)->firstOrFail();
        $file = DB::table('repasse_case_evidence')->where('id', $document)
            ->where('tenant_id', $t->source_tenant_id)->where('repasse_case_id', $t->source_vehicle_id)
            ->whereNull('archived_at')->firstOrFail();
        return $this->privateFile($file);
    }

    private function privateFile(object $file): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);
        if (! hash_equals($file->sha256, hash('sha256', Storage::disk('local')->get($file->storage_path)))) {
            abort(409, 'Arquivo com integridade divergente.');
        }
        return Storage::disk('local')->download($file->storage_path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function event(object $grant, Request $request, string $name): void
    {
        DB::table('repasse_case_events')->insert([
            'tenant_id' => $grant->owner_tenant_id, 'repasse_case_id' => $grant->vehicle_id,
            'actor_user_id' => $request->user('api')->id,
            'event_type' => $name, 'created_at' => now(),
        ]);
    }

    private function assertFields(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown) throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo não permitido.'));
    }
}
