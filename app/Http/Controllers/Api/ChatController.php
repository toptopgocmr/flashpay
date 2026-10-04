<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexFlowService;
use App\Services\Peex\PeexCorridors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Messagerie entre utilisateurs FlashPay (style WhatsApp) : texte, photos /
 * captures d'écran, vidéos courtes, notes vocales ; modification et transfert
 * de messages ; photos de profil. Les appels audio sont dans ChatCallController.
 * (Le support FlashPay reste dans /support/tickets.)
 */
class ChatController extends Controller
{
    public const IMAGE_MAX_KB = 3072;  // 3 Mo
    public const VIDEO_MAX_KB = 5120;  // 5 Mo
    public const AUDIO_MAX_KB = 3072;  // 3 Mo (≈ 5 min de note vocale)
    public const FORWARD_MAX = 5;      // destinataires par transfert

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
        $photos = $this->withPhoto($others->keys()->all());
        $last = ChatMessage::whereIn('id', ChatMessage::selectRaw('MAX(id)')->whereIn('conversation_id', $convs->pluck('id'))->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');
        $unread = ChatMessage::selectRaw('conversation_id, COUNT(*) n')->whereIn('conversation_id', $convs->pluck('id'))
            ->where('sender_id', '!=', $me->id)->whereNull('read_at')->groupBy('conversation_id')->pluck('n', 'conversation_id');

        return response()->json([
            'data' => $convs->map(fn ($c) => [
                'id' => $c->id,
                'user' => $this->person($others[$c->otherId($me)] ?? null, $photos),
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

    /**
     * Messages (les 50 derniers, ou ceux après ?after=id / avant ?before=id) ;
     * marque comme lus. Avec ?since=<date ISO> : renvoie aussi les messages
     * modifiés depuis (edits) et le dernier de mes messages lu (read_upto).
     */
    public function messages(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        abort_unless($conversation->hasParticipant($me), 403);
        $now = now();

        $q = $conversation->messages()->orderByDesc('id');
        if ($after = (int) $request->query('after')) {
            $q->where('id', '>', $after);
        }
        if ($before = (int) $request->query('before')) {
            $q->where('id', '<', $before);
        }
        $rows = $q->limit(50)->get()->reverse()->values();

        $conversation->messages()->where('sender_id', '!=', $me->id)->whereNull('read_at')->update(['read_at' => $now]);

        $out = [
            'data' => $rows->map(fn ($m) => $this->present($m, $me)),
            'user' => $this->person(User::find($conversation->otherId($me))),
            'now' => $now->toIso8601String(),
            'read_upto' => (int) $conversation->messages()->where('sender_id', $me->id)->whereNotNull('read_at')->max('id'),
        ];

        if ($since = $request->query('since')) {
            try {
                $from = \Illuminate\Support\Carbon::parse($since)->subSeconds(2);
                $out['edits'] = $conversation->messages()->whereNotNull('edited_at')->where('edited_at', '>=', $from)
                    ->orderBy('id')->limit(50)->get()->map(fn ($m) => $this->present($m, $me))->values();
            } catch (\Throwable) {
                $out['edits'] = [];
            }
        }

        return response()->json($out);
    }

    public function send(Request $request, ChatConversation $conversation, NotificationService $notify)
    {
        $me = $request->user();
        abort_unless($conversation->hasParticipant($me), 403);

        $request->validate([
            'body' => 'nullable|string|max:2000',
            'file' => 'nullable|file',
            'kind' => 'nullable|in:audio',          // note vocale
            'duration' => 'nullable|integer|min:0|max:3600',
        ]);

        $file = $request->file('file');
        $body = trim((string) $request->input('body')) ?: null;
        if (! $file && ! $body) {
            return response()->json(['message' => 'Message vide.'], 422);
        }

        $data = ['conversation_id' => $conversation->id, 'sender_id' => $me->id, 'type' => 'text', 'body' => $body];
        if ($file) {
            [$kind, $mime] = $this->kindOf($file, $request->input('kind'));
            if (! $kind) {
                return response()->json(['message' => 'Format non pris en charge : envoyez une photo (JPG, PNG), une vidéo (MP4) ou une note vocale.'], 422);
            }
            $maxKb = match ($kind) {
                'video' => self::VIDEO_MAX_KB,
                'audio' => self::AUDIO_MAX_KB,
                default => self::IMAGE_MAX_KB,
            };
            if ($file->getSize() > $maxKb * 1024) {
                return response()->json(['message' => match ($kind) {
                    'video' => 'Vidéo trop lourde : 5 Mo maximum (environ 20 à 30 secondes).',
                    'audio' => 'Note vocale trop longue : 3 Mo maximum (environ 5 minutes).',
                    default => 'Image trop lourde : 3 Mo maximum.',
                }], 422);
            }
            $data = array_merge($data, [
                'type' => $kind,
                'mime' => $mime,
                'size' => $file->getSize(),
                'duration' => $kind === 'audio' ? (int) $request->input('duration', 0) : null,
                'content' => base64_encode(file_get_contents($file->getRealPath())),
            ]);
        }

        $m = ChatMessage::create($data);
        $this->afterSend($conversation, $m, $me, $notify);

        return response()->json($this->present($m, $me), 201);
    }

    /** Modifier un de mes messages (texte ou légende), dans les 15 minutes. */
    public function update(Request $request, ChatMessage $message)
    {
        $me = $request->user();
        abort_unless($message->conversation->hasParticipant($me), 403);
        $v = $request->validate(['body' => 'required|string|max:2000']);

        if ((int) $message->sender_id !== $me->id) {
            return response()->json(['message' => 'Vous ne pouvez modifier que vos propres messages.'], 403);
        }
        if (! in_array($message->type, ['text', 'image', 'video'], true)) {
            return response()->json(['message' => 'Ce message ne peut pas être modifié.'], 422);
        }
        if ($message->forwarded) {
            return response()->json(['message' => 'Un message transféré ne peut pas être modifié.'], 422);
        }
        if ($message->created_at && $message->created_at->lt(now()->subMinutes(ChatMessage::EDIT_MINUTES))) {
            return response()->json(['message' => 'Un message ne peut être modifié que dans les ' . ChatMessage::EDIT_MINUTES . ' minutes après son envoi.'], 422);
        }
        $body = trim($v['body']);
        if ($body === '' && $message->type === 'text') {
            return response()->json(['message' => 'Message vide.'], 422);
        }

        $message->forceFill(['body' => $body ?: null, 'edited_at' => now()])->save();

        return response()->json($this->present($message, $me));
    }

    /** Transférer un message (texte, photo, vidéo, note vocale) vers une ou plusieurs discussions. */
    public function forward(Request $request, ChatMessage $message, NotificationService $notify)
    {
        $me = $request->user();
        abort_unless($message->conversation->hasParticipant($me), 403);
        $v = $request->validate([
            'conversation_ids' => 'required|array|min:1|max:' . self::FORWARD_MAX,
            'conversation_ids.*' => 'integer',
        ]);
        if ($message->type === 'call') {
            return response()->json(['message' => 'Un appel ne peut pas être transféré.'], 422);
        }

        $targets = ChatConversation::whereIn('id', array_unique($v['conversation_ids']))->get()
            ->filter(fn ($c) => $c->hasParticipant($me));
        if ($targets->isEmpty()) {
            return response()->json(['message' => 'Aucune discussion valide.'], 422);
        }

        $raw = $message->getRawOriginal('content');
        $sent = [];
        foreach ($targets as $c) {
            $m = ChatMessage::create([
                'conversation_id' => $c->id,
                'sender_id' => $me->id,
                'type' => $message->type,
                'forwarded' => true,
                'body' => $message->body,
                'mime' => $message->mime,
                'size' => $message->size,
                'duration' => $message->duration,
                'content' => $raw,
            ]);
            $this->afterSend($c, $m, $me, $notify);
            $sent[] = ['conversation_id' => $c->id, 'message' => $this->present($m, $me)];
        }

        return response()->json(['data' => $sent, 'message' => count($sent) > 1 ? 'Message transféré à ' . count($sent) . ' discussions.' : 'Message transféré.'], 201);
    }

    /** Fichier d'un message (photo / vidéo / note vocale), réservé aux deux participants. */
    public function file(Request $request, ChatMessage $message)
    {
        abort_unless($message->conversation->hasParticipant($request->user()), 403);
        return $message->fileResponse();
    }

    /** Lien temporaire (10 min) pour lire une vidéo / une note vocale. */
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

    /** Photo de profil d'un contact (seulement si on a une discussion avec lui). */
    public function userPhoto(Request $request, User $user)
    {
        $me = $request->user();
        if ($user->id !== $me->id) {
            [$one, $two] = $me->id < $user->id ? [$me->id, $user->id] : [$user->id, $me->id];
            abort_unless(ChatConversation::where('user_one_id', $one)->where('user_two_id', $two)->exists(), 403);
        }
        $doc = KycController::latestPhoto($user);
        abort_unless($doc, 404);
        return $doc->fileResponse();
    }

    // ------------------------------------------------------------------ Outils

    /** Horodatage de la discussion + notification du destinataire. */
    protected function afterSend(ChatConversation $conversation, ChatMessage $m, User $me, NotificationService $notify): void
    {
        $conversation->forceFill(['last_message_at' => now()])->save();
        $other = User::find($conversation->otherId($me));
        $notify->toUser($other, 'chat_message', 'Nouveau message de ' . $me->full_name, $this->preview($m, $other)['text'], [
            'data' => ['conversation_id' => $conversation->id],
            'sms' => false,
        ]);
    }

    /** Type réel du fichier (contenu d'abord, extension en secours) : ['image'|'video'|'audio'|null, mime]. */
    protected function kindOf(\Illuminate\Http\UploadedFile $file, ?string $hint = null): array
    {
        $images = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic'];
        $videos = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', '3gp' => 'video/3gpp', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
        $audios = ['m4a' => 'audio/mp4', 'aac' => 'audio/aac', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'webm' => 'audio/webm', '3gp' => 'audio/3gpp', 'amr' => 'audio/amr'];
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $ext = strtolower($file->getClientOriginalExtension());

        // Note vocale : un m4a / webm est souvent détecté comme « vidéo ».
        if ($hint === 'audio') {
            if (str_starts_with($mime, 'audio/') || in_array($mime, ['video/mp4', 'video/webm', 'video/3gpp', 'application/mp4', 'application/ogg', 'application/octet-stream', ''], true)) {
                return ['audio', $audios[$ext] ?? (str_starts_with($mime, 'audio/') ? $mime : 'audio/mp4')];
            }
            return [null, $mime];
        }

        if (in_array($mime, $images, true)) {
            return ['image', $mime];
        }
        if (in_array($mime, $videos, true) || in_array($mime, ['application/mp4', 'video/x-m4v'], true)) {
            return ['video', $videos[$ext] ?? 'video/mp4'];
        }
        if (str_starts_with($mime, 'audio/')) {
            return ['audio', $mime];
        }
        // Contenu non reconnu (octet-stream…) : on se fie à l'extension.
        if (in_array($mime, ['application/octet-stream', ''], true)) {
            if (isset($images[$ext])) return ['image', $images[$ext]];
            if (isset($videos[$ext])) return ['video', $videos[$ext]];
            if (isset($audios[$ext])) return ['audio', $audios[$ext]];
        }
        return [null, $mime];
    }

    /** Ids (parmi $ids) des utilisateurs qui ont une photo de profil. */
    protected function withPhoto(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        return KycDocument::where('type', 'profile_photo')->whereIn('user_id', $ids)
            ->where(fn ($q) => $q->where('status', '!=', 'rejected')->orWhere('rejection_reason', 'Remplacé par un nouvel envoi'))
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function person(?User $u, ?array $photos = null): ?array
    {
        if (! $u) {
            return null;
        }
        $photos ??= $this->withPhoto([$u->id]);
        return ['id' => $u->id, 'name' => $u->full_name, 'phone' => $u->phone, 'has_photo' => in_array($u->id, $photos, true)];
    }

    public static function callLabel(ChatMessage $m, bool $mine): string
    {
        $d = (int) $m->duration;
        $len = $d > 0 ? ' · ' . intdiv($d, 60) . ':' . str_pad((string) ($d % 60), 2, '0', STR_PAD_LEFT) : '';
        return match ($m->body) {
            'missed', 'cancelled' => $mine ? '📞 Appel sans réponse' : '📞 Appel manqué',
            'rejected', 'busy' => $mine ? '📞 Appel refusé' : '📞 Appel refusé',
            default => '📞 Appel audio' . $len,
        };
    }

    protected function preview(ChatMessage $m, ?User $viewer): array
    {
        $mine = $viewer && $m->sender_id === $viewer->id;
        $fw = $m->forwarded ? '↪ ' : '';
        $text = match ($m->type) {
            'image' => $fw . '📷 Photo' . ($m->body ? ' · ' . $m->body : ''),
            'video' => $fw . '🎬 Vidéo' . ($m->body ? ' · ' . $m->body : ''),
            'audio' => $fw . '🎤 Note vocale' . ($m->duration ? ' (' . intdiv((int) $m->duration, 60) . ':' . str_pad((string) ((int) $m->duration % 60), 2, '0', STR_PAD_LEFT) . ')' : ''),
            'call' => self::callLabel($m, $mine),
            default => $fw . (string) $m->body,
        };
        return [
            'text' => mb_strimwidth($text, 0, 80, '…'),
            'mine' => $mine,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    protected function present(ChatMessage $m, User $me): array
    {
        $mine = $m->sender_id === $me->id;
        return [
            'id' => $m->id,
            'type' => $m->type,
            'body' => $m->body,
            'mime' => $m->mime,
            'size' => $m->size,
            'duration' => $m->duration,
            'forwarded' => (bool) $m->forwarded,
            'edited' => $m->edited_at !== null,
            'editable' => $mine && in_array($m->type, ['text', 'image', 'video'], true) && ! $m->forwarded
                && $m->created_at && $m->created_at->gte(now()->subMinutes(ChatMessage::EDIT_MINUTES)),
            'mine' => $mine,
            'read' => $m->read_at !== null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
