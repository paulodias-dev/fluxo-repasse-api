<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CollaborationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ConnectionsController extends Controller
{
    public function __construct(private readonly CollaborationAccess $access) {}

    public function search(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)->where('id', '<>', $actor->id)
            ->first(['id', 'name', 'email', 'tenant_id']);
        $connection = $user ? DB::table('user_connections')
            ->where('user_low_id', min($actor->id, $user->id))
            ->where('user_high_id', max($actor->id, $user->id))
            ->first() : null;
        // Not found does not reveal any other user data.
        return response()->json(['data' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'connection_id' => $connection?->id, 'status' => $connection?->status,
        ] : null])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $rows = DB::table('user_connections as c')
            ->join('users as low', 'low.id', '=', 'c.user_low_id')
            ->join('users as high', 'high.id', '=', 'c.user_high_id')
            ->where(function ($q) use ($actor) {
                $q->where('c.user_low_id', $actor->id)->orWhere('c.user_high_id', $actor->id);
            })
            ->whereIn('c.status', ['pending', 'accepted'])
            ->select('c.*', 'low.name as low_name', 'low.email as low_email',
                'high.name as high_name', 'high.email as high_email')
            ->orderByDesc('c.updated_at')->limit(100)->get()
            ->map(function ($row) use ($actor) {
                $low = (int) $row->user_low_id === (int) $actor->id;
                return [
                    'id' => (int) $row->id, 'user_id' => (int) ($low ? $row->user_high_id : $row->user_low_id),
                    'name' => $low ? $row->high_name : $row->low_name,
                    'email' => $low ? $row->high_email : $row->low_email,
                    'status' => $row->status, 'incoming' => $row->status === 'pending' && (int) $row->requested_by !== (int) $actor->id,
                    'favorite' => (bool) ($low ? $row->favorite_low : $row->favorite_high),
                ];
            });
        return response()->json(['data' => $rows])->header('Cache-Control', 'no-store, private');
    }

    public function requestConnection(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        // Não aceitar IDs sequenciais: o convite exige conhecimento do e-mail exato.
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        if ($email === mb_strtolower($actor->email)) {
            throw ValidationException::withMessages(['email' => 'Escolha outra pessoa.']);
        }
        $recipient = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)->first(['id', 'name']);
        abort_unless($recipient, 404);
        $id = (int) $recipient->id;
        $low = min($id, (int) $actor->id);
        $high = max($id, (int) $actor->id);
        DB::transaction(function () use ($request, $actor, $recipient, $low, $high) {
            $found = DB::table('user_connections')
                ->where('user_low_id', $low)->where('user_high_id', $high)->lockForUpdate()->first();
            if ($found && $found->status === 'accepted') return;
            if ($found && $found->status === 'blocked') {
                throw ValidationException::withMessages(['email' => 'Esta conexão está bloqueada.']);
            }
            if ($found && $found->status === 'pending' && (int) $found->requested_by !== (int) $actor->id) {
                throw ValidationException::withMessages(['email' => 'Aceite a solicitação recebida.']);
            }
            if ($found && $found->status === 'pending') return;
            if ($found) {
                DB::table('user_connections')->where('id', $found->id)->update([
                    'status' => 'pending', 'requested_by' => $actor->id, 'updated_at' => now(),
                ]);
                $connectionId = $found->id;
            } else {
                $connectionId = DB::table('user_connections')->insertGetId([
                    'user_low_id' => $low, 'user_high_id' => $high,
                    'requested_by' => $actor->id, 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->access->notify($recipient->id, 'connection_request', [
                'connection_id' => $connectionId, 'sender_name' => $actor->name,
            ]);
        });
        return response()->json(['message' => 'Solicitação enviada.'], 201);
    }

    public function respond(Request $request, int $connection): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        DB::transaction(function () use ($actor, $connection, $data) {
            $c = DB::table('user_connections')->where('id', $connection)->lockForUpdate()->first();
            abort_unless($c && in_array((int) $actor->id, [(int) $c->user_low_id, (int) $c->user_high_id], true), 404);
            if ($c->status !== 'pending' || (int) $c->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['connection' => 'Solicitação indisponível.']);
            }
            $accepted = (bool) $data['accept'];
            DB::table('user_connections')->where('id', $c->id)->update([
                'status' => $accepted ? 'accepted' : 'declined', 'updated_at' => now(),
            ]);
            if ($accepted) {
                $chat = DB::table('collab_conversations')->where('connection_id', $c->id)->first();
                $chatId = $chat?->id ?? DB::table('collab_conversations')->insertGetId([
                    'kind' => 'direct', 'connection_id' => $c->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->access->conversationMember($chatId, (int) $c->user_low_id);
                $this->access->conversationMember($chatId, (int) $c->user_high_id);
            }
            $this->access->notify((int) $c->requested_by, $accepted ? 'connection_accepted' : 'connection_declined',
                ['connection_id' => $c->id]);
        });
        return response()->json(['message' => 'Solicitação respondida.']);
    }

    public function favorite(Request $request, int $connection): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate(['favorite' => ['required', 'boolean']]);
        $row = DB::table('user_connections')->where('id', $connection)->where('status', 'accepted')
            ->where(function ($q) use ($actor) {
                $q->where('user_low_id', $actor->id)->orWhere('user_high_id', $actor->id);
            })->first();
        abort_unless($row, 404);
        DB::table('user_connections')->where('id', $row->id)->update([
            (int) $row->user_low_id === (int) $actor->id ? 'favorite_low' : 'favorite_high' => (bool) $data['favorite'],
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Preferência atualizada.']);
    }

    public function remove(Request $request, int $connection): JsonResponse
    {
        $actor = $this->access->actor($request);
        DB::transaction(function () use ($actor, $connection) {
            $row = DB::table('user_connections')->where('id', $connection)->lockForUpdate()->first();
            abort_unless($row && in_array((int) $actor->id, [(int) $row->user_low_id, (int) $row->user_high_id], true), 404);
            DB::table('user_connections')->where('id', $connection)->update([
                'status' => 'blocked', 'updated_at' => now(),
            ]);
            $chat = DB::table('collab_conversations')->where('connection_id', $connection)->first();
            if ($chat) DB::table('collab_conversation_members')->where('conversation_id', $chat->id)
                ->update(['removed_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['message' => 'Conexão removida.']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        return response()->json(['data' => DB::table('collab_notifications')
            ->where('recipient_id', $actor->id)->orderByDesc('id')->limit(60)
            ->get(['id', 'type', 'data', 'created_at', 'read_at'])])
            ->header('Cache-Control', 'no-store, private');
    }

    public function readNotification(Request $request, int $notification): JsonResponse
    {
        $actor = $this->access->actor($request);
        $updated = DB::table('collab_notifications')->where('id', $notification)
            ->where('recipient_id', $actor->id)->update(['read_at' => now()]);
        abort_unless($updated, 404);
        return response()->json(['message' => 'Notificação lida.']);
    }
}
