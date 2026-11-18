<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantTable;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PartyLinkController extends Controller
{
    public function index(Party $party): JsonResponse
    {
        $links = TenantTable::table('party_links as links')
            ->join('parties as related', 'related.id', '=', 'links.related_party_id')
            ->where('links.party_id', $party->id)
            ->select('links.id', 'links.party_id', 'links.related_party_id',
                'links.description', 'links.is_active', 'links.created_at',
                'links.updated_at', 'related.name as related_name',
                'related.type as related_type', 'related.is_active as related_is_active')
            ->orderByDesc('links.id')->paginate(15);
        $links->getCollection()->transform(fn ($row) => $this->represent($row));

        return response()->json($links)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Party $party): JsonResponse
    {
        $data = $request->validate([
            'related_party_id' => ['required', 'integer', 'distinct', 'exists:parties,id', Rule::notIn([$party->id])],
            'description' => ['required', 'string', 'min:2', 'max:180'],
        ]);
        $description = trim($data['description']);
        if (mb_strlen($description) < 2) {
            throw ValidationException::withMessages(['description' => 'Informe uma descrição válida.']);
        }

        $id = DB::transaction(function () use ($party, $data, $description, $request): int {
            $related = Party::query()->whereKey($data['related_party_id'])->lockForUpdate()->firstOrFail();
            if (! $party->fresh()->is_active || ! $related->is_active) {
                throw ValidationException::withMessages(['related_party_id' => 'Ambos os cadastros devem estar ativos.']);
            }
            $exists = TenantTable::table('party_links')->where('party_id', $party->id)
                ->where('related_party_id', $related->id)->where('description', $description)->exists();
            if ($exists) {
                throw ValidationException::withMessages(['description' => 'Este vínculo já existe.']);
            }
            $now = now();
            $id = TenantTable::table('party_links')->insertGetId([
                'party_id' => $party->id,
                'related_party_id' => $related->id,
                'description' => $description,
                'is_active' => true,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'updated_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->event($party->id, $request, 'party_link_created');
            return $id;
        });

        return response()->json(['data' => $this->represent($this->find($party, $id))], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Party $party, int $link): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['description' => 'Informe uma alteração.']);
        }

        $changed = DB::transaction(function () use ($request, $party, $link, $data): bool {
            $row = TenantTable::table('party_links')->where('party_id', $party->id)
                ->where('id', $link)->lockForUpdate()->first();
            if (! $row) abort(404);
            $description = array_key_exists('description', $data) ? trim($data['description']) : $row->description;
            if (mb_strlen($description) < 2) {
                throw ValidationException::withMessages(['description' => 'Informe uma descrição válida.']);
            }
            $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $row->is_active;
            if ($active && (! $party->fresh()->is_active ||
                ! Party::query()->whereKey($row->related_party_id)->value('is_active'))) {
                throw ValidationException::withMessages(['is_active' => 'Cadastros inativos não podem ter vínculos ativados.']);
            }
            if ($description === $row->description && $active === (bool) $row->is_active) return false;
            $duplicate = TenantTable::table('party_links')->where('party_id', $party->id)
                ->where('related_party_id', $row->related_party_id)
                ->where('description', $description)->where('id', '!=', $link)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['description' => 'Este vínculo já existe.']);
            }
            TenantTable::table('party_links')->where('id', $link)->update([
                'description' => $description, 'is_active' => $active,
                'updated_by' => $request->user('api')->getAuthIdentifier(), 'updated_at' => now(),
            ]);
            $this->event($party->id, $request, $active !== (bool) $row->is_active
                ? ($active ? 'party_link_activated' : 'party_link_closed') : 'party_link_updated');
            return true;
        });

        return response()->json(['data' => $this->represent($this->find($party, $link)), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    private function represent(object $row): array
    {
        $data = (array) $row;
        $data['is_active'] = (bool) $data['is_active'];
        $data['related_is_active'] = (bool) $data['related_is_active'];
        return $data;
    }

    private function find(Party $party, int $id): object
    {
        return TenantTable::table('party_links as links')->join('parties as related', 'related.id', '=', 'links.related_party_id')
            ->where('links.party_id', $party->id)->where('links.id', $id)
            ->select('links.id', 'links.party_id', 'links.related_party_id', 'links.description',
                'links.is_active', 'links.created_at', 'links.updated_at',
                'related.name as related_name', 'related.type as related_type',
                'related.is_active as related_is_active')->firstOrFail();
    }

    private function event(int $partyId, Request $request, string $type): void
    {
        TenantTable::table('party_events')->insert([
            'party_id' => $partyId, 'actor_user_id' => $request->user('api')->getAuthIdentifier(),
            'event_type' => $type, 'created_at' => now(),
        ]);
    }
}
