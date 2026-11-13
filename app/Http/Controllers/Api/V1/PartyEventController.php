<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartyEventController extends Controller
{
    public function __invoke(Request $request, Party $party): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $events = DB::table('party_events')
            ->where('party_id', $party->id)
            ->select(['id', 'event_type', 'actor_user_id', 'created_at'])
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json(['party_id' => $party->id, 'events' => $events])
            ->header('Cache-Control', 'no-store');
    }
}
