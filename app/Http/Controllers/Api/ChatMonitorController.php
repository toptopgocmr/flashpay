<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\Audit;
use App\Support\SupportChat;
use Illuminate\Http\Request;

/**
 * Supervision des échanges (conformité / LCB-FT) : toutes les discussions
 * entre utilisateurs, notes vocales, photos et appels, en lecture seule.
 * Chaque consultation est inscrite au journal d'audit. Les utilisateurs en
 * sont informés dans l'application (mention dans chaque discussion).
 */
class ChatMonitorController extends Controller
{
    public function index(Request $request)
    {
        $q = ChatConversation::query()->withCount(['messages', 'calls']);
        if ($k = $request->input('kind')) {
            $q->where('kind', $k);
        }
        if ($s = trim((string) $request->input('q'))) {
            $digits = preg_replace('/\D/', '', $s);
            $ids = User::where('full_name', 'like', "%{$s}%")->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))->pluck('id');
            $q->where(fn ($w) => $w->whereIn('user_one_id', $ids)->orWhereIn('user_two_id', $ids));
        }
        if ($request->boolean('with_media')) {
            $q->whereHas('messages', fn ($m) => $m->whereIn('type', ['audio', 'image', 'video']));
        }
        $page = $q->orderByDesc('last_message_at')->paginate(30);
        $users = User::whereIn('id', $page->getCollection()->flatMap(fn ($c) => [$c->user_one_id, $c->user_two_id])->unique())->get(['id', 'full_name', 'phone'])->keyBy('id');

        return response()->json($page->through(fn ($c) => [
            'id' => $c->id,
            'kind' => $c->kind,
            'status' => $c->status,
            'participants' => collect([$c->user_one_id, $c->user_two_id])->map(fn ($id) => $this->who($users[$id] ?? null))->values(),
            'messages' => $c->messages_count,
            'calls' => $c->calls_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ]));
    }

    /** Messages d'une discussion (100 par page, ?before=id pour remonter). */
    public function messages(Request $request, ChatConversation $conversation)
    {
        $q = $conversation->messages()->with('sender:id,full_name,phone', 'agent:id,full_name', 'replyTo')->orderByDesc('id');
        if ($before = (int) $request->query('before')) {
            $q->where('id', '<', $before);
        }
        $rows = $q->limit(100)->get()->reverse()->values();
        Audit::log('chat.monitor_view', $conversation, ['count' => $rows->count()]);

        $users = User::whereIn('id', [$conversation->user_one_id, $conversation->user_two_id])->get(['id', 'full_name', 'phone'])->keyBy('id');

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'kind' => $conversation->kind,
                'participants' => collect([$conversation->user_one_id, $conversation->user_two_id])->map(fn ($id) => $this->who($users[$id] ?? null))->values(),
            ],
            'data' => $rows->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'sender' => $this->who($m->sender),
                'agent' => $m->agent?->full_name,
                'type' => $m->type,
                'body' => $m->body,
                'lang' => $m->lang,
                'mime' => $m->mime,
                'size' => $m->size,
                'duration' => $m->duration,
                'forwarded' => (bool) $m->forwarded,
                'edited_at' => $m->edited_at?->toIso8601String(),
                'reply_to' => $m->replyTo ? ['id' => $m->replyTo->id, 'body' => mb_strimwidth((string) $m->replyTo->body, 0, 120, '…'), 'type' => $m->replyTo->type] : null,
                'read_at' => $m->read_at?->toIso8601String(),
                'at' => $m->created_at?->toIso8601String(),
            ]),
            'calls' => ChatCall::with('agent:id,full_name')->where('conversation_id', $conversation->id)->latest()->limit(50)->get()->map(fn (ChatCall $c) => [
                'id' => $c->id,
                'caller_id' => $c->caller_id,
                'status' => $c->status,
                'seconds' => $c->talkSeconds(),
                'agent' => $c->agent?->full_name,
                'at' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function file(ChatMessage $message)
    {
        Audit::log('chat.monitor_file', $message);
        return $message->fileResponse();
    }

    /** Journal de tous les appels audio. */
    public function calls(Request $request)
    {
        $page = ChatCall::with('caller:id,full_name,phone', 'callee:id,full_name,phone', 'agent:id,full_name')->latest()->paginate(40);
        return response()->json($page->through(fn (ChatCall $c) => [
            'id' => $c->id,
            'conversation_id' => $c->conversation_id,
            'caller' => $this->who($c->caller),
            'callee' => $this->who($c->callee),
            'agent' => $c->agent?->full_name,
            'status' => $c->status,
            'seconds' => $c->talkSeconds(),
            'at' => $c->created_at?->toIso8601String(),
        ]));
    }

    protected function who(?User $u): ?array
    {
        if (! $u) {
            return null;
        }
        return SupportChat::isSupportUser($u)
            ? ['id' => $u->id, 'name' => SupportChat::NAME, 'phone' => null, 'support' => true]
            : ['id' => $u->id, 'name' => $u->full_name, 'phone' => $u->phone];
    }
}
