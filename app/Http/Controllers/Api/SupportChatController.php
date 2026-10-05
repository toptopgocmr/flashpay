<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\SupportChat;
use Illuminate\Http\Request;

/**
 * Console : discussions « Support FlashPay » avec les clients (texte, photos,
 * notes vocales, appels audio). Chaque requête de l'agent est rejouée au nom
 * du compte support via ChatController / ChatCallController : le client voit
 * « Support FlashPay », la console garde le nom de l'agent (agent_id).
 */
class SupportChatController extends Controller
{
    /** Boîte de réception : discussions support, non lues d'abord. */
    public function index(Request $request)
    {
        $support = SupportChat::user();
        $q = ChatConversation::where('kind', 'support')->with('assignee:id,full_name');
        if ($st = $request->input('status')) {
            $q->where('status', $st);
        }
        if ($s = trim((string) $request->input('q'))) {
            $digits = preg_replace('/\D/', '', $s);
            $ids = User::where('full_name', 'like', "%{$s}%")->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))->pluck('id');
            $q->where(fn ($w) => $w->whereIn('user_one_id', $ids)->orWhereIn('user_two_id', $ids));
        }
        $convs = $q->orderByDesc('last_message_at')->limit(100)->get();
        $clients = User::whereIn('id', $convs->map(fn ($c) => $c->otherId($support)))->get(['id', 'full_name', 'phone', 'language', 'kyc_tier'])->keyBy('id');
        $unread = ChatMessage::selectRaw('conversation_id, COUNT(*) n')->whereIn('conversation_id', $convs->pluck('id'))
            ->where('sender_id', '!=', $support->id)->whereNull('read_at')->groupBy('conversation_id')->pluck('n', 'conversation_id');
        $last = ChatMessage::whereIn('id', ChatMessage::selectRaw('MAX(id)')->whereIn('conversation_id', $convs->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');
        $ringing = ChatCall::where('status', 'ringing')->where('callee_id', $support->id)->pluck('conversation_id')->flip();

        $rows = $convs->map(function ($c) use ($support, $clients, $unread, $last, $ringing) {
            $u = $clients[$c->otherId($support)] ?? null;
            $m = $last[$c->id] ?? null;
            return [
                'id' => $c->id,
                'status' => $c->status,
                'client' => $u ? ['id' => $u->id, 'name' => $u->full_name, 'phone' => $u->phone, 'language' => $u->language, 'kyc_tier' => $u->kyc_tier] : null,
                'assigned' => $c->assignee?->full_name,
                'unread' => (int) ($unread[$c->id] ?? 0),
                'ringing' => isset($ringing[$c->id]),
                'last' => $m ? ['type' => $m->type, 'body' => $m->body ? mb_strimwidth($m->body, 0, 90, '…') : null, 'from_client' => $m->sender_id !== $support->id, 'at' => $m->created_at?->toIso8601String()] : null,
                'updated_at' => optional($c->last_message_at ?? $c->created_at)->toIso8601String(),
            ];
        })->sortByDesc(fn ($r) => [$r['ringing'] ? 1 : 0, $r['unread'] > 0 ? 1 : 0, $r['updated_at']])->values();

        return response()->json(['data' => $rows, 'unread_total' => (int) $unread->sum()]);
    }

    /** Démarrer (ou retrouver) la discussion support d'un client depuis la console. */
    public function openFor(Request $request, User $user)
    {
        abort_if(SupportChat::isSupportUser($user), 422);
        $c = SupportChat::conversationFor($user);
        return response()->json(['id' => $c->id]);
    }

    public function messages(Request $request, ChatConversation $conversation)
    {
        $this->guard($conversation);
        SupportChat::actAs($request, $request->user());
        return app()->call([app(ChatController::class), 'messages'], ['request' => $request, 'conversation' => $conversation]);
    }

    public function send(Request $request, ChatConversation $conversation)
    {
        $this->guard($conversation);
        $staff = $request->user();
        if (! $conversation->assigned_to) {
            $conversation->forceFill(['assigned_to' => $staff->id])->save();
        }
        SupportChat::actAs($request, $staff);
        return app()->call([app(ChatController::class), 'send'], ['request' => $request, 'conversation' => $conversation]);
    }

    public function file(Request $request, ChatMessage $message)
    {
        $this->guard($message->conversation);
        return $message->fileResponse();
    }

    public function link(Request $request, ChatMessage $message)
    {
        $this->guard($message->conversation);
        SupportChat::actAs($request, $request->user());
        return app()->call([app(ChatController::class), 'link'], ['request' => $request, 'message' => $message]);
    }

    public function setStatus(Request $request, ChatConversation $conversation)
    {
        $this->guard($conversation);
        $v = $request->validate(['status' => 'nullable|in:open,closed', 'assign_to_me' => 'nullable|boolean']);
        if (isset($v['status'])) {
            $conversation->status = $v['status'];
        }
        if (! empty($v['assign_to_me'])) {
            $conversation->assigned_to = $request->user()->id;
        }
        $conversation->save();
        return response()->json(['status' => $conversation->status, 'assigned' => $conversation->assignee?->full_name]);
    }

    // ------------------------------------------------------------ Appels

    /** Appel d'un client vers le support qui sonne (toutes les consoles l'entendent). */
    public function incomingCall(Request $request)
    {
        SupportChat::actAs($request, $request->user());
        return app()->call([app(ChatCallController::class), 'incoming'], ['request' => $request]);
    }

    public function config()
    {
        return app(ChatCallController::class)->config();
    }

    public function startCall(Request $request, ChatConversation $conversation)
    {
        $this->guard($conversation);
        SupportChat::actAs($request, $request->user());
        return app()->call([app(ChatCallController::class), 'start'], ['request' => $request, 'conversation' => $conversation]);
    }

    public function callShow(Request $request, ChatCall $call, string $action = 'show')
    {
        $this->guard($call->conversation);
        SupportChat::actAs($request, $request->user());
        abort_unless(in_array($action, ['show', 'accept', 'reject', 'end'], true), 404);
        return app()->call([app(ChatCallController::class), $action], ['request' => $request, 'call' => $call]);
    }

    public function callStep(Request $request, ChatCall $call, string $action)
    {
        return $this->callShow($request, $call, $action);
    }

    protected function guard(?ChatConversation $c): void
    {
        abort_unless($c && $c->kind === 'support', 404);
    }
}
