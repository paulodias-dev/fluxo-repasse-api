<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CollaborationAccess;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ConversationsController extends Controller
{
    public function __construct(
        private readonly CollaborationAccess $access,
        private readonly MediaStorage $media,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $records = DB::table('collab_conversation_members as m')
            ->join('collab_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('repasse_cases as v', 'v.id', '=', 'c.vehicle_id')
            ->where('m.user_id', $actor->id)->whereNull('m.removed_at')
            ->select('c.id', 'c.kind', 'c.vehicle_id', 'v.title as vehicle_title',
                'c.connection_id', 'm.last_read_message_id', 'm.joined_after_message_id')
            ->orderByDesc('c.updated_at')->limit(100)->get();
        $result = [];
        foreach ($records as $row) {
            try {
                $this->access->activeMember($request, (int) $row->id);
            } catch (HttpException $e) {
                continue;
            }
            $last = DB::table('collab_messages')->where('conversation_id', $row->id)
                ->where('id', '>', $row->joined_after_message_id)->orderByDesc('id')
                ->first(['id', 'body', 'created_at', 'file_name', 'sender_id']);
            $unread = DB::table('collab_messages')->where('conversation_id', $row->id)
                ->where('id', '>', max((int) $row->last_read_message_id, (int) $row->joined_after_message_id))
                ->where('sender_id', '<>', $actor->id)->count();
            $others = DB::table('collab_conversation_members as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.conversation_id', $row->id)
                ->where('m.user_id', '<>', $actor->id)->whereNull('m.removed_at')
                ->get(['u.id', 'u.name']);
            $result[] = [
                'id' => (int) $row->id, 'kind' => $row->kind,
                'vehicle_id' => $row->vehicle_id,
                'title' => $row->kind === 'vehicle' ? $row->vehicle_title : ($others->first()?->name ?? 'Conversa'),
                'participants' => $others, 'unread' => $unread, 'last_message' => $last,
            ];
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function direct(Request $request): JsonResponse
    {
        $actor = $this->access->actor($request);
        $data = $request->validate(['connection_id' => ['required', 'integer', 'min:1']]);
        $chatId = DB::transaction(function () use ($data, $actor) {
            $c = DB::table('user_connections')->where('id', $data['connection_id'])
                ->where('status', 'accepted')->lockForUpdate()->first();
            abort_unless($c && in_array((int) $actor->id, [(int) $c->user_low_id, (int) $c->user_high_id], true), 404);
            $found = DB::table('collab_conversations')->where('connection_id', $c->id)->first();
            $id = $found?->id ?? DB::table('collab_conversations')->insertGetId([
                'kind' => 'direct', 'connection_id' => $c->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->access->conversationMember($id, $c->user_low_id);
            $this->access->conversationMember($id, $c->user_high_id);

            return $id;
        });

        return response()->json(['data' => ['id' => $chatId]])->header('Cache-Control', 'no-store, private');
    }

    private function visibleMessages(int $conversation, int $floor, int $after = 0, int $limit = 50): array
    {
        return DB::table('collab_messages as m')
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.conversation_id', $conversation)->where('m.id', '>', max($floor, $after))
            ->orderBy('m.id')->limit($limit)
            ->get(['m.id', 'm.sender_id', 'u.name as sender_name', 'm.body', 'm.file_name',
                'm.file_mime', 'm.file_size', 'm.created_at'])->all();
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $member = $this->access->activeMember($request, $conversation);
        $params = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($params['before'])) {
            $rows = DB::table('collab_messages as m')->join('users as u', 'u.id', '=', 'm.sender_id')
                ->where('m.conversation_id', $conversation)
                ->where('m.id', '>', $member->joined_after_message_id)
                ->where('m.id', '<', $params['before'])
                ->orderByDesc('m.id')->limit(50)
                ->get(['m.id', 'm.sender_id', 'u.name as sender_name', 'm.body',
                    'm.file_name', 'm.file_mime', 'm.file_size', 'm.created_at'])
                ->reverse()->values();
        } elseif (isset($params['after'])) {
            $rows = collect($this->visibleMessages($conversation,
                (int) $member->joined_after_message_id, (int) $params['after']));
        } else {
            // Initial load retrieves latest visible 50 instead of oldest 50.
            $rows = DB::table('collab_messages as m')->join('users as u', 'u.id', '=', 'm.sender_id')
                ->where('m.conversation_id', $conversation)
                ->where('m.id', '>', $member->joined_after_message_id)
                ->orderByDesc('m.id')->limit(50)
                ->get(['m.id', 'm.sender_id', 'u.name as sender_name', 'm.body',
                    'm.file_name', 'm.file_mime', 'm.file_size', 'm.created_at'])
                ->reverse()->values();
        }

        return response()->json(['data' => $rows])->header('Cache-Control', 'no-store, private');
    }

    /** Do not acknowledge a reused idempotency key for a different message or attachment. */
    private function assertSameAttempt(object $existing, string $body, ?string $fileSha): void
    {
        if (($existing->body ?? null) !== ($body === '' ? null : $body) ||
            ($existing->file_sha256 ?? null) !== $fileSha) {
            abort(409, 'Identificador de tentativa reutilizado com outra mensagem ou arquivo.');
        }
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $actor = $this->access->actor($request);
        $member = $this->access->activeMember($request, $conversation);
        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'file' => ['sometimes', 'nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'request_key' => ['required', 'uuid'],
        ]);
        $body = trim($data['body'] ?? '');
        $fileSha = $request->hasFile('file')
            ? hash_file('sha256', $request->file('file')->getRealPath()) : null;
        if ($body === '' && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['body' => 'Digite uma mensagem ou selecione um arquivo.']);
        }
        $existing = DB::table('collab_messages')->where('conversation_id', $conversation)
            ->where('sender_id', $actor->id)->where('request_key', $data['request_key'])->first();
        if ($existing) {
            $this->assertSameAttempt($existing, $body, $fileSha);

            return response()->json(['data' => ['id' => $existing->id, 'created' => false]])
                ->header('Cache-Control', 'no-store, private');
        }

        $path = null;
        $meta = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $this->media->store($file, 'collab-messages/'.$conversation);
            $meta = [
                'file_path' => $path,
                'file_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                'file_mime' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'file_sha256' => hash_file('sha256', $file->getRealPath()),
            ];
        }
        try {
            $id = DB::transaction(function () use ($request, $actor, $conversation, $body, $data, $meta, $fileSha) {
                // Serializa tentativas concorrentes do mesmo chat antes de verificar
                // a chave idempotente: só uma mensagem será gravada.
                DB::table('collab_conversations')->where('id', $conversation)
                    ->lockForUpdate()->firstOrFail();
                $this->access->activeMember($request, $conversation);
                $again = DB::table('collab_messages')->where('conversation_id', $conversation)
                    ->where('sender_id', $actor->id)->where('request_key', $data['request_key'])->first();
                if ($again) {
                    $this->assertSameAttempt($again, $body, $fileSha);

                    return ['id' => (int) $again->id, 'created' => false];
                }
                $id = DB::table('collab_messages')->insertGetId([
                    'conversation_id' => $conversation, 'sender_id' => $actor->id,
                    'body' => $body !== '' ? $body : null, 'request_key' => $data['request_key'],
                    ...$meta, 'created_at' => now(),
                ]);
                DB::table('collab_conversations')->where('id', $conversation)->update(['updated_at' => now()]);
                $others = DB::table('collab_conversation_members')->where('conversation_id', $conversation)
                    ->where('user_id', '<>', $actor->id)->whereNull('removed_at')->pluck('user_id');
                foreach ($others as $recipient) {
                    $this->access->notify((int) $recipient,
                        'new_message', ['chat_id' => $conversation, 'message_id' => $id]);
                }

                return ['id' => $id, 'created' => true];
            }, 3);
            if (! $id['created'] && $path) {
                $this->media->delete($path);
            }
        } catch (Throwable $e) {
            if ($path) {
                $this->media->delete($path);
            }
            throw $e;
        }

        return response()->json(['data' => $id], $id['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        $member = $this->access->activeMember($request, $conversation);
        $latest = (int) DB::table('collab_messages')->where('conversation_id', $conversation)->max('id');
        DB::table('collab_conversation_members')->where('id', $member->id)
            ->update(['last_read_message_id' => max((int) $member->last_read_message_id,
                (int) $member->joined_after_message_id, $latest), 'updated_at' => now()]);

        return response()->json(['message' => 'Lido.']);
    }

    public function download(Request $request, int $conversation, int $message): StreamedResponse
    {
        $member = $this->access->activeMember($request, $conversation);
        $file = DB::table('collab_messages')->where('id', $message)
            ->where('conversation_id', $conversation)
            ->where('id', '>', $member->joined_after_message_id)
            ->whereNotNull('file_path')->first();
        abort_unless($file, 404);
        if (! $this->media->verify($file->file_path, (int) $file->file_size, $file->file_sha256)) {
            abort(409, 'Arquivo indisponível ou divergente.');
        }

        return $this->media->download($file->file_path, $file->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * SSE over Bearer fetch, with bounded lifecycle (18 s) and ACL recheck.
     * No external broker required in the MVP; each connected client consumes
     * a PHP worker until timeout. Scale with Reverb/Redis before high traffic.
     */
    public function stream(Request $request, int $conversation): StreamedResponse
    {
        $member = $this->access->activeMember($request, $conversation);
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);
        $cursor = max((int) ($data['after'] ?? 0), (int) $member->joined_after_message_id);

        return response()->stream(function () use ($request, $conversation, $cursor) {
            $expires = microtime(true) + 18;
            echo ": conectado\n\n";
            @ob_flush();
            flush();
            while (microtime(true) < $expires && ! connection_aborted()) {
                try {
                    $current = $this->access->activeMember($request, $conversation);
                } catch (Throwable) {
                    echo "event: revoked\ndata: {}\n\n";
                    @ob_flush();
                    flush();
                    break;
                }
                $rows = $this->visibleMessages($conversation, (int) $current->joined_after_message_id, $cursor, 50);
                foreach ($rows as $message) {
                    $cursor = (int) $message->id;
                    echo 'id: '.$cursor."\n";
                    echo "event: message\n";
                    echo 'data: '.json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
                }
                if ($rows === []) {
                    echo ": heartbeat\n\n";
                }
                @ob_flush();
                flush();
                sleep(1);
            }
        }, 200, ['Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-store, private', 'X-Accel-Buffering' => 'no']);
    }
}
