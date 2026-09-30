<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexFlowService;
use App\Services\Peex\PeexCorridors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Messagerie entre utilisateurs FlashPay : texte, photos / captures d'écran et
 * vidéos courtes. (Le support FlashPay reste dans /support/tickets.)
 */
class ChatController extends Controller
{
    public const IMAGE_MAX_KB = 3072;  // 3 Mo
    public const VIDEO_MAX_KB = 5120;  // 5 Mo

    public function __construct(protected PeexFlowService $flows)
    {
    }

    /** Conversations de l'utilisateur, la plus récente en premier. */
    public function index(Request $request)
    {
        $me = $request->user();
        $convs = ChatConversation::where('user_one_id', $me->id)->orWhere('user_two_id', $me->id)
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();

        $others = User::whereIn('id', $convs->map(fn ($c) => $c->otherId($me)))->get()->keyBy('id');
        $last = ChatMessage::whereIn('id', ChatMessage::selectRaw('MAX(id)')->whereIn('conversation_id', $convs->pluck('id'))->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');
        $unread = ChatMessage::selectRaw('conversation_id, COUNT(*) n')->whereIn('conversation_id', $convs->pluck('id'))
            ->where('sender_id', '!=', $me->id)->whereNull('read_at')->groupBy('conversation_id')->pluck('n', 'conversation_id');

        return response()->json([
            'data' => $convs->map(fn ($c) => [
                'id' => $c->id,
                'user' => $this->person($others[$c->otherId($me)] ?? null),
                'last_message' => isset($last[$c->id]) ? $this->preview($last[$c->id], $me) : null,
                'unread' => (int) ($unread[$c->id] ?? 0),
                'updated_at' => optional($c->last_message_at ?? $c->created_at)->toIso8601String(),
            ])->values(),
            'unread_total' => (int) $unread->sum(),
        ]);
    }

    /** Ouvre (ou retrouve) une discussion avec un numéro inscrit sur FlashPay. */
    public function open(Request $request)
    {
        $v = $request->validate(['phone' => 'required|string|max:25']);
        $me = $request->user();
        $route = app(PeexCorridors::class)->resolve($v['phone']);
        $other = $this->flows->findUserByPhone($route['phone']);

        if (! $other || ($other->status ?? 'active') !== 'active') {
            return response()->json(['message' => "Ce numéro n'a pas de compte FlashPay. Invitez-le à installer l'application pour discuter."], 404);
        }
        if ($other->id === $me->id) {
            return response()->json(['message' => 'Vous ne pouvez pas discuter avec vous-même.'], 422);
        }

        $c = ChatConversation::between($me, $other);

        return response()->json(['id' => $c->id, 'user' => $this->person($other)]);
    }

    /** Messages (les 50 derniers, ou ceux après ?after=id) ; marque comme lus. */
    public function messages(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        abort_unless($conversation->hasParticipant($me), 403);

        $q = $conversation->messages()->orderByDesc('id');
        if ($after = (int) $request->query('after')) {
            $q->where('id', '>', $after);
        }
        if ($before = (int) $request->query('before')) {
            $q->where('id', '<', $before);
        }
        $rows = $q->limit(50)->get()->reverse()->values();

        $conversation->messages()->where('sender_id', '!=', $me->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'data' => $rows->map(fn ($m) => $this->present($m, $me)),
            'user' => $this->person(User::find($conversation->otherId($me))),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation, NotificationService $notify)
    {
        $me = $request->user();
        abort_unless($conversation->hasParticipant($me), 403);

        $request->validate([
            'body' => 'nullable|string|max:2000',
            'file' => 'nullable|file',
        ]);

        $file = $request->file('file');
        $body = trim((string) $request->input('body')) ?: null;
        if (! $file && ! $body) {
            return response()->json(['message' => 'Message vide.'], 422);
        }

        $data = ['conversation_id' => $conversation->id, 'sender_id' => $me->id, 'type' => 'text', 'body' => $body];
        if ($file) {
            [$kind, $mime] = $this->kindOf($file);
            if (! $kind) {
                return response()->json(['message' => 'Format non pris en charge : envoyez une photo (JPG, PNG) ou une vidéo (MP4).'], 422);
            }
            $isVideo = $kind === 'video';
            $maxKb = $isVideo ? self::VIDEO_MAX_KB : self::IMAGE_MAX_KB;
            if ($file->getSize() > $maxKb * 1024) {
                return response()->json(['message' => $isVideo
                    ? 'Vidéo trop lourde : 5 Mo maximum (environ 20 à 30 secondes).'
                    : 'Image trop lourde : 3 Mo maximum.'], 422);
            }
            $data = array_merge($data, [
                'type' => $isVideo ? 'video' : 'image',
                'mime' => $mime,
                'size' => $file->getSize(),
                'content' => base64_encode(file_get_contents($file->getRealPath())),
            ]);
        }

        $m = ChatMessage::create($data);
        $conversation->forceFill(['last_message_at' => now()])->save();

        $other = User::find($conversation->otherId($me));
        $notify->toUser($other, 'chat_message', 'Nouveau message de ' . $me->full_name, $this->preview($m, $other)['text'], [
            'data' => ['conversation_id' => $conversation->id],
            'sms' => false,
        ]);

        return response()->json($this->present($m, $me), 201);
    }

    /** Fichier d'un message (photo / vidéo), réservé aux deux participants. */
    public function file(Request $request, ChatMessage $message)
    {
        abort_unless($message->conversation->hasParticipant($request->user()), 403);
        return $message->fileResponse();
    }

    /** Lien temporaire (10 min) pour lire une vidéo dans le lecteur du téléphone. */
    public function link(Request $request, ChatMessage $message)
    {
        abort_unless($message->conversation->hasParticipant($request->user()), 403);
        return response()->json(['url' => URL::temporarySignedRoute('chat.media', now()->addMinutes(10), ['message' => $message->id])]);
    }

    /** Route web signée (sans jeton) utilisée par le lien temporaire. */
    public function signedFile(ChatMessage $message)
    {
        return $message->fileResponse();
    }

    /** Type réel du fichier (contenu d'abord, extension en secours) : ['image'|'video'|null, mime]. */
    protected function kindOf(\Illuminate\Http\UploadedFile $file): array
    {
        $images = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic'];
        $videos = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', '3gp' => 'video/3gpp', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($mime, $images, true)) {
            return ['image', $mime];
        }
        if (in_array($mime, $videos, true) || in_array($mime, ['application/mp4', 'video/x-m4v'], true)) {
            return ['video', $videos[$ext] ?? 'video/mp4'];
        }
        // Contenu non reconnu (octet-stream…) : on se fie à l'extension.
        if (in_array($mime, ['application/octet-stream', ''], true)) {
            if (isset($images[$ext])) return ['image', $images[$ext]];
            if (isset($videos[$ext])) return ['video', $videos[$ext]];
        }
        return [null, $mime];
    }

    protected function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->full_name, 'phone' => $u->phone] : null;
    }

    protected function preview(ChatMessage $m, ?User $viewer): array
    {
        $text = match ($m->type) {
            'image' => '📷 Photo' . ($m->body ? ' · ' . $m->body : ''),
            'video' => '🎬 Vidéo' . ($m->body ? ' · ' . $m->body : ''),
            default => (string) $m->body,
        };
        return [
            'text' => mb_strimwidth($text, 0, 80, '…'),
            'mine' => $viewer && $m->sender_id === $viewer->id,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    protected function present(ChatMessage $m, User $me): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'body' => $m->body,
            'mime' => $m->mime,
            'size' => $m->size,
            'mine' => $m->sender_id === $me->id,
            'read' => $m->read_at !== null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
