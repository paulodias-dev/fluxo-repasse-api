<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePartyRequest;
use App\Http\Requests\Api\V1\UpdatePartyRequest;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PartyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(['all', 'person', 'company'])],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Party::query()->select([
            'id', 'type', 'name', 'email', 'phone', 'notes',
            'is_active', 'created_at', 'updated_at',
        ]);

        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (($filters['type'] ?? 'all') !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return response()->json($query->orderByDesc('id')->paginate(15))
            ->header('Cache-Control', 'no-store');
    }

    public function show(Party $party): JsonResponse
    {
        return response()->json(['data' => $this->represent($party)])
            ->header('Cache-Control', 'no-store');
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        $party = DB::transaction(function () use ($request): Party {
            $party = Party::query()->create($request->validated());
            $this->record($party, $request, 'party_created');

            return $party;
        });

        return response()->json(['data' => $this->represent($party)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdatePartyRequest $request, Party $party): JsonResponse
    {
        $changed = DB::transaction(function () use ($request, $party): bool {
            $current = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            $current->fill($request->validated());

            if (! $current->isDirty()) {
                return false;
            }

            $current->save();
            $this->record($current, $request, 'party_updated');

            return true;
        });

        return response()->json(['data' => $this->represent($party->fresh()), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, Party $party): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        $changed = DB::transaction(function () use ($request, $party, $active): bool {
            $current = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();

            if ($current->is_active === $active) {
                return false;
            }

            $current->is_active = $active;
            $current->save();
            $this->record($current, $request, $active ? 'party_reactivated' : 'party_deactivated');

            return true;
        });

        return response()->json(['data' => $this->represent($party->fresh()), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    private function record(Party $party, Request $request, string $type): void
    {
        DB::table('party_events')->insert([
            'party_id' => $party->id,
            'actor_user_id' => $request->user('api')?->getAuthIdentifier(),
            'event_type' => $type,
            'created_at' => now(),
        ]);
    }

    private function represent(Party $party): array
    {
        return $party->only([
            'id', 'type', 'name', 'email', 'phone', 'notes',
            'is_active', 'created_at', 'updated_at',
        ]);
    }
}
