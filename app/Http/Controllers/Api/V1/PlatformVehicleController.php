<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PlatformVehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'in:active,removed,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = DB::table('repasse_cases as v')
            ->leftJoin('users as owner', 'owner.id', '=', 'v.created_by')
            ->join('tenants as company', 'company.id', '=', 'v.tenant_id')
            ->select([
                'v.id', 'v.title', 'v.status', 'v.plate', 'v.brand', 'v.model',
                'v.created_at', 'v.admin_deleted_at', 'v.admin_deletion_reason',
                'owner.id as owner_id', 'owner.name as owner_name', 'owner.email as owner_email',
                'company.id as company_id', 'company.name as company_name',
            ]);

        $status = $filters['status'] ?? 'active';
        if ($status === 'removed') {
            $query->whereNotNull('v.admin_deleted_at');
        } elseif ($status !== 'all') {
            $query->whereNull('v.admin_deleted_at');
        }

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where(function ($search) use ($escaped): void {
                $search->where('v.title', 'like', '%'.$escaped.'%')
                    ->orWhere('v.plate', 'like', '%'.$escaped.'%')
                    ->orWhere('owner.name', 'like', '%'.$escaped.'%')
                    ->orWhere('owner.email', 'like', '%'.$escaped.'%')
                    ->orWhere('company.name', 'like', '%'.$escaped.'%');
            });
        }

        return response()->json($query->orderByDesc('v.id')->paginate(30))
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, int $vehicle): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $actor = $request->user('api');

        $result = DB::transaction(function () use ($vehicle, $data, $actor): array {
            $row = DB::table('repasse_cases')->where('id', $vehicle)->lockForUpdate()->first();
            abort_unless($row, 404);

            $changed = $row->admin_deleted_at === null;
            $deletedAt = $row->admin_deleted_at ?? now();
            if ($changed) {
                DB::table('repasse_cases')->where('id', $vehicle)->update([
                    'admin_deleted_at' => $deletedAt,
                    'admin_deleted_by' => $actor->getAuthIdentifier(),
                    'admin_deletion_reason' => trim($data['reason']),
                    'updated_at' => $deletedAt,
                ]);
            }

            DB::table('vehicle_access_grants')
                ->where('vehicle_id', $vehicle)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            DB::table('vehicle_collaboration_requests')
                ->where('vehicle_id', $vehicle)
                ->where('status', 'pending')
                ->update([
                    'status' => 'revoked',
                    'resolved_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            DB::table('collab_conversation_members')
                ->whereIn('conversation_id', DB::table('collab_conversations')
                    ->where('vehicle_id', $vehicle)->select('id'))
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            if ($changed) {
                DB::table('repasse_case_events')->insert([
                    'tenant_id' => $row->tenant_id,
                    'repasse_case_id' => $vehicle,
                    'actor_user_id' => $actor->getAuthIdentifier(),
                    'event_type' => 'admin_vehicle_deleted',
                    'details' => json_encode([
                        'reason' => trim($data['reason']),
                        'previous_status' => $row->status,
                        'owner_id' => $row->created_by,
                        'tenant_id' => $row->tenant_id,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => $deletedAt,
                ]);
            }

            return ['changed' => $changed, 'id' => (int) $row->id];
        });

        return response()->json($result, $result['changed'] ? 200 : 409)
            ->header('Cache-Control', 'no-store, private');
    }

    public function history(int $vehicle): JsonResponse
    {
        $row = DB::table('repasse_cases as v')
            ->join('tenants as company', 'company.id', '=', 'v.tenant_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'v.created_by')
            ->where('v.id', $vehicle)
            ->first(['v.id', 'v.title', 'v.plate', 'v.status', 'v.admin_deleted_at',
                'v.admin_deletion_reason', 'company.name as company_name',
                'owner.name as owner_name', 'owner.email as owner_email']);
        abort_unless($row, 404);

        $events = DB::table('repasse_case_events as e')
            ->leftJoin('users as actor', 'actor.id', '=', 'e.actor_user_id')
            ->where('e.repasse_case_id', $vehicle)
            ->orderByDesc('e.id')
            ->paginate(50, [
                'e.id', 'e.event_type', 'e.actor_user_id', 'actor.name as actor_name',
                'e.details', 'e.created_at',
            ]);
        $events->through(static function (object $event): object {
            $event->details = $event->details === null
                ? null
                : json_decode((string) $event->details, true);

            return $event;
        });

        return response()->json(['vehicle' => $row, 'events' => $events])
            ->header('Cache-Control', 'no-store, private');
    }

    public function image(int $vehicle): StreamedResponse
    {
        $file = DB::table('repasse_case_evidence as photo')
            ->join('repasse_cases as v', 'v.id', '=', 'photo.repasse_case_id')
            ->where('v.id', $vehicle)->whereNull('v.admin_deleted_at')
            ->whereNull('photo.archived_at')->where('photo.mime', 'like', 'image/%')
            ->where(function ($type): void {
                $type->where('photo.document_type', 'foto_veiculo')->orWhereNull('photo.document_type');
            })->orderByDesc('photo.is_cover')->orderByDesc('photo.id')
            ->first(['photo.storage_path', 'photo.original_name', 'photo.mime']);
        abort_unless($file && app(MediaStorage::class)->exists($file->storage_path), 404);

        $name = Str::ascii((string) $file->original_name);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        return app(MediaStorage::class)->download($file->storage_path, $name ?: 'veiculo.jpg', [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
