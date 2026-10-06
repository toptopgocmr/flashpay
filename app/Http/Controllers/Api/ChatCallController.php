<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Appels audio dans le chat (WebRTC). Le serveur ne transporte pas la voix :
 * il échange seulement l'offre et la réponse SDP (ICE « non-trickle » : les
 * candidats réseau sont déjà inclus dans le SDP), puis les deux téléphones se
 * parlent directement. Serveurs STUN/TURN configurables dans .env :
 *   WEBRTC_STUN_URLS=stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302
 *   WEBRTC_TURN_URL=turn:turn.exemple.com:3478   (facultatif, réseaux stricts)
 *   WEBRTC_TURN_USERNAME=…  WEBRTC_TURN_PASSWORD=…
 */
class ChatCallController extends Controller
{
    /** Serveurs STUN/TURN à utiliser avant de préparer l'offre. */
    public function config()
    {
        return response()->json(['ice_servers' => self::iceServers(), 'ring_seconds' => ChatCall::RING_SECONDS]);
    }

    /** Démarrer un appel : {offer} -> {id, status, ice_servers} */
    public function start(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        abort_unless($conversation->hasParticipant($me), 403);
        $v = $request->validate(['offer' => 'required|string|max:60000']);
        $callee = User::find($conversation->otherId($me));
        abort_unless($callee, 404);

        $this->expireStale();
        // Support : plusieurs agents, plusieurs appels simultanés possibles.
        $supportSide = \App\Support\SupportChat::isSupportUser($me) || \App\Support\SupportChat::isSupportUser($callee);

        // Le contact est déjà en ligne avec quelqu'un : occupé.
        $busy = ! \App\Support\SupportChat::isSupportUser($callee) && ChatCall::whereIn('status', ['ringing', 'accepted'])
            ->where(fn ($q) => $q->whereIn('caller_id', [$callee->id])->orWhereIn('callee_id', [$callee->id]))
            ->exists();
        if ($busy) {
            $call = ChatCall::create(['conversation_id' => $conversation->id, 'caller_id' => $me->id, 'callee_id' => $callee->id, 'status' => 'busy', 'ended_at' => now()]);
            $this->logMessage($call);
            return response()->json(['message' => $callee->full_name . ' est déjà en communication. Réessayez plus tard.', 'status' => 'busy'], 409);
        }

        // Un ancien appel de ma part encore ouvert : on le clôt (pas pour le compte support partagé).
        $stale = ChatCall::whereIn('status', ['ringing', 'accepted']);
        $supportSide
            ? $stale->where('conversation_id', $conversation->id)
            : $stale->where(fn ($q) => $q->where('caller_id', $me->id)->orWhere('callee_id', $me->id));
        $stale->get()->each(fn ($c) => $this->finish($c, $c->status === 'ringing' ? 'cancelled' : 'ended', $me));

        $call = ChatCall::create([
            'conversation_id' => $conversation->id,
            'caller_id' => $me->id,
            'callee_id' => $callee->id,
            'status' => 'ringing',
            'offer' => self::sdp($v['offer']),
            'agent_id' => \App\Support\SupportChat::isSupportUser($me) ? \App\Support\SupportChat::agent()?->id : null,
        ]);

        if (\App\Support\SupportChat::isSupportUser($callee)) {
            app(NotificationService::class)->toAdmins('support_call', '📞 Appel support de ' . $me->full_name, 'À prendre dans la console : Support › Discussions.', ['severity' => 'warning', 'data' => ['conversation_id' => $conversation->id, 'call_id' => $call->id]]);
            return response()->json($this->present($call, $me) + ['ice_servers' => self::iceServers()], 201);
        }

        // Notification « appel entrant » : utile si l'app du contact est en arrière-plan.
        app(NotificationService::class)->toUser($callee, 'chat_call', '📞 Appel de ' . $me->full_name, 'Ouvrez FlashPay pour répondre.', [
            'data' => ['conversation_id' => $conversation->id, 'call_id' => $call->id],
            'sms' => false,
        ]);

        return response()->json($this->present($call, $me) + ['ice_servers' => self::iceServers()], 201);
    }

    /** Appel entrant qui sonne pour moi (interrogé toutes les 3 s par l'app). */
    public function incoming(Request $request)
    {
        $me = $request->user();
        $this->expireStale();
        $call = ChatCall::where('callee_id', $me->id)->where('status', 'ringing')->latest('id')->first();
        if (! $call) {
            return response()->json(['call' => null]);
        }
        return response()->json(['call' => $this->present($call, $me) + ['offer' => $call->offer ? self::sdp($call->offer) : null], 'ice_servers' => self::iceServers()]);
    }

    /** État d'un appel (l'appelant y récupère la réponse SDP une fois décroché). */
    public function show(Request $request, ChatCall $call)
    {
        $me = $request->user();
        abort_unless($call->isParticipant($me), 403);
        $this->expireStale();
        $call->refresh();
        if ($call->status === 'accepted') {
            $call->touch(); // battement de cœur : les deux téléphones interrogent pendant l'appel
        }
        $out = $this->present($call, $me);
        if ($call->status === 'accepted' && (int) $call->caller_id === $me->id) {
            $out['answer'] = $call->answer ? self::sdp($call->answer) : null;
        }
        return response()->json($out);
    }

    /** Décrocher : {answer} */
    public function accept(Request $request, ChatCall $call)
    {
        $me = $request->user();
        abort_unless((int) $call->callee_id === $me->id, 403);
        $v = $request->validate(['answer' => 'required|string|max:60000']);
        $this->expireStale();
        $call->refresh();
        if ($call->status !== 'ringing') {
            return response()->json(['message' => "L'appel est terminé.", 'status' => $call->status], 409);
        }
        $call->forceFill(['status' => 'accepted', 'answer' => self::sdp($v['answer']), 'answered_at' => now(), 'agent_id' => (\App\Support\SupportChat::isSupportUser($me) ? \App\Support\SupportChat::agent()?->id : null) ?? $call->agent_id])->save();
        return response()->json($this->present($call, $me));
    }

    public function reject(Request $request, ChatCall $call)
    {
        $me = $request->user();
        abort_unless((int) $call->callee_id === $me->id, 403);
        if ($call->status === 'ringing') {
            $this->finish($call, 'rejected', $me);
        }
        return response()->json($this->present($call->refresh(), $me));
    }

    /** Raccrocher (ou annuler avant que le contact ne décroche). */
    public function end(Request $request, ChatCall $call)
    {
        $me = $request->user();
        abort_unless($call->isParticipant($me), 403);
        if ($call->status === 'ringing') {
            $this->finish($call, (int) $call->caller_id === $me->id ? 'missed' : 'rejected', $me);
        } elseif ($call->status === 'accepted') {
            $this->finish($call, 'ended', $me);
        }
        return response()->json($this->present($call->refresh(), $me));
    }

    // ------------------------------------------------------------------ Outils

    /** SDP normalisé : lignes terminées par CRLF, y compris la dernière (sinon « Failed to parse SessionDescription »). */
    public static function sdp(?string $sdp): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $sdp));
        return implode("\r\n", array_filter($lines, fn ($l) => $l !== '')) . "\r\n";
    }

    public static function iceServers(): array
    {
        return array_merge(self::baseIceServers(), self::providerTurn());
    }

    /**
     * Relais TURN fournis par un service (identifiants temporaires, mis en cache) :
     *  - Cloudflare : WEBRTC_CLOUDFLARE_TURN_KEY_ID + WEBRTC_CLOUDFLARE_TURN_TOKEN (1 000 Go/mois gratuits)
     *  - Metered    : WEBRTC_METERED_DOMAIN (ex. flashpay.metered.live) + WEBRTC_METERED_API_KEY
     */
    public static function providerTurn(): array
    {
        $kid = config('flashpay.webrtc.cloudflare_key_id');
        $tok = config('flashpay.webrtc.cloudflare_token');
        if ($kid && $tok) {
            $v = Cache::remember('webrtc:turn:cloudflare', now()->addHours(12), function () use ($kid, $tok) {
                try {
                    $r = Http::withToken($tok)->timeout(8)->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$kid}/credentials/generate", ['ttl' => 86400]);
                    $ice = $r->json('iceServers');
                    return $r->successful() && $ice ? (array_is_list($ice) ? $ice : [$ice]) : null;
                } catch (\Throwable) {
                    return null;
                }
            });
            if ($v) {
                return $v;
            }
            Cache::forget('webrtc:turn:cloudflare');
        }
        $dom = config('flashpay.webrtc.metered_domain');
        $key = config('flashpay.webrtc.metered_api_key');
        if ($dom && $key) {
            $v = Cache::remember('webrtc:turn:metered', now()->addHours(6), function () use ($dom, $key) {
                try {
                    $r = Http::timeout(8)->get('https://' . preg_replace('#^https?://#', '', rtrim((string) $dom, '/')) . '/api/v1/turn/credentials', ['apiKey' => $key]);
                    $j = $r->json();
                    return $r->successful() && is_array($j) && $j ? $j : null;
                } catch (\Throwable) {
                    return null;
                }
            });
            if ($v) {
                return $v;
            }
            Cache::forget('webrtc:turn:metered');
        }
        return [];
    }

    protected static function baseIceServers(): array
    {
        $stun = array_values(array_filter(array_map('trim', explode(',', (string) (config('flashpay.webrtc.stun_urls') ?: 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302')))));
        $servers = $stun ? [['urls' => $stun]] : [];
        $provider = (config('flashpay.webrtc.cloudflare_key_id') && config('flashpay.webrtc.cloudflare_token')) || (config('flashpay.webrtc.metered_domain') && config('flashpay.webrtc.metered_api_key'));
        if (($turn = config('flashpay.webrtc.turn_url')) && strtolower((string) $turn) !== 'none' && ! ($provider && str_contains((string) $turn, 'openrelay'))) {
            $servers[] = [
                'urls' => array_values(array_filter(array_map('trim', explode(',', (string) $turn)))),
                'username' => (string) config('flashpay.webrtc.turn_username', ''),
                'credential' => (string) config('flashpay.webrtc.turn_password', ''),
            ];
        }
        return $servers;
    }

    /** Appels qui sonnent depuis trop longtemps -> manqués ; appels « oubliés » -> terminés. */
    protected function expireStale(): void
    {
        ChatCall::where('status', 'ringing')->where('created_at', '<', now()->subSeconds(ChatCall::RING_SECONDS))
            ->get()->each(fn ($c) => $this->finish($c, 'missed', null));
        // Plus aucun téléphone ne donne signe de vie (app fermée, réseau perdu) : appel terminé.
        ChatCall::where('status', 'accepted')->where('updated_at', '<', now()->subSeconds(40))
            ->get()->each(fn ($c) => $this->finish($c, 'ended', null));
    }

    protected function finish(ChatCall $call, string $status, ?User $by): void
    {
        if (! $call->isOpen()) {
            return;
        }
        $call->forceFill(['status' => $status, 'ended_at' => now(), 'ended_by' => $by?->id])->save();
        $this->logMessage($call);

        if ($status === 'missed') {
            $caller = $call->caller;
            app(NotificationService::class)->toUser($call->callee, 'chat_call_missed', 'Appel manqué', 'Appel audio manqué de ' . ($caller?->full_name ?? 'un contact') . '.', [
                'data' => ['conversation_id' => $call->conversation_id],
                'sms' => false,
            ]);
        }
    }

    /** Trace de l'appel dans la discussion (comme WhatsApp). */
    protected function logMessage(ChatCall $call): void
    {
        ChatMessage::create([
            'conversation_id' => $call->conversation_id,
            'sender_id' => $call->caller_id,
            'type' => 'call',
            'body' => $call->status, // ended | missed | rejected | busy | cancelled
            'duration' => $call->talkSeconds() ?: null,
        ]);
        ChatConversation::whereKey($call->conversation_id)->update(['last_message_at' => now()]);
    }

    protected function present(ChatCall $call, User $me): array
    {
        $otherId = (int) $call->caller_id === $me->id ? $call->callee_id : $call->caller_id;
        $other = User::find($otherId);
        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'status' => $call->status,
            'outgoing' => (int) $call->caller_id === $me->id,
            'user' => $other ? (\App\Support\SupportChat::isSupportUser($other)
                ? ['id' => $other->id, 'name' => \App\Support\SupportChat::NAME, 'phone' => null, 'support' => true]
                : ['id' => $other->id, 'name' => $other->full_name, 'phone' => $other->phone]) : null,
            'answered_at' => $call->answered_at?->toIso8601String(),
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
