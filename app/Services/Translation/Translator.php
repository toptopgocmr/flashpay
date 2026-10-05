<?php

namespace App\Services\Translation;

use App\Models\ChatMessage;
use App\Models\ChatMessageTranslation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traduction automatique des messages du chat.
 * Moteurs (config flashpay.translation.driver) :
 *  - mymemory : gratuit, sans clé (quota ~5 000 caractères/jour, 50 000 avec MYMEMORY_EMAIL) ;
 *  - google   : Google Cloud Translation (GOOGLE_TRANSLATE_KEY), le plus complet (lingala, swahili…) ;
 *  - deepl    : DeepL (DEEPL_KEY), très bonne qualité pour fr/en/es/pt/de/ru/zh.
 * En cas d'échec (quota, réseau), le message reste affiché dans sa langue d'origine.
 */
class Translator
{
    /** Langues de l'app -> langue réellement utilisée pour traduire (kituba : français). */
    public const APP_TO_TEXT = ['ktu' => 'fr'];

    public function enabled(): bool
    {
        return (bool) config('flashpay.translation.enabled', true);
    }

    /** Langue de lecture/écriture d'un utilisateur, normalisée pour la traduction. */
    public static function langOf(?User $u): string
    {
        $l = strtolower((string) ($u?->language ?: 'fr'));
        return self::APP_TO_TEXT[$l] ?? $l;
    }

    /** Traduction (en cache) d'un message pour un lecteur, ou null si inutile / impossible. */
    public function forMessage(ChatMessage $m, string $target, bool $allowRemote = true): ?string
    {
        $body = trim((string) $m->body);
        $source = self::APP_TO_TEXT[$m->lang] ?? $m->lang;
        if (! $this->enabled() || $body === '' || ! $source || $source === $target || ! $this->worthTranslating($body)) {
            return null;
        }
        $cached = ChatMessageTranslation::where('message_id', $m->id)->where('lang', $target)->value('text');
        if ($cached !== null || ! $allowRemote) {
            return $cached;
        }
        $text = $this->translate($body, $source, $target);
        if ($text === null || trim($text) === '' || mb_strtolower(trim($text)) === mb_strtolower($body)) {
            return null;
        }
        ChatMessageTranslation::updateOrCreate(['message_id' => $m->id, 'lang' => $target], ['text' => $text, 'engine' => $this->driver()]);
        return $text;
    }

    /** Message modifié : on efface les traductions périmées. */
    public function forget(ChatMessage $m): void
    {
        ChatMessageTranslation::where('message_id', $m->id)->delete();
    }

    public function translate(string $text, string $from, string $to): ?string
    {
        // Moteur en panne / quota atteint : on évite de ralentir chaque envoi pendant 10 min.
        if (Cache::get('chat_translation_down')) {
            return null;
        }
        try {
            $out = match ($this->driver()) {
                'google' => $this->google($text, $from, $to),
                'deepl' => $this->deepl($text, $from, $to),
                default => $this->mymemory($text, $from, $to),
            };
            return $out !== null ? html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        } catch (\Throwable $e) {
            Log::warning('Traduction chat impossible : ' . $e->getMessage());
            Cache::put('chat_translation_down', true, now()->addMinutes(10));
            return null;
        }
    }

    protected function driver(): string
    {
        return (string) config('flashpay.translation.driver', 'mymemory');
    }

    /** Emojis, montants, numéros… : rien à traduire. */
    protected function worthTranslating(string $t): bool
    {
        return (bool) preg_match('/\p{L}{2,}/u', $t);
    }

    protected function code(string $l, string $engine): string
    {
        if ($l === 'zh') {
            return $engine === 'deepl' ? 'ZH' : 'zh-CN';
        }
        if ($engine === 'deepl') {
            return strtoupper($l);
        }
        return $l;
    }

    protected function mymemory(string $text, string $from, string $to): ?string
    {
        $res = Http::connectTimeout(3)->timeout(6)->get('https://api.mymemory.translated.net/get', array_filter([
            'q' => mb_substr($text, 0, 500),
            'langpair' => $this->code($from, 'mymemory') . '|' . $this->code($to, 'mymemory'),
            'de' => config('flashpay.translation.mymemory_email'),
        ]));
        $j = $res->json();
        $status = (int) ($j['responseStatus'] ?? $res->status());
        $t = (string) ($j['responseData']['translatedText'] ?? '');
        if ($status !== 200 || $t === '' || str_contains($t, 'MYMEMORY WARNING') || str_contains($t, 'QUERY LENGTH LIMIT')) {
            if (in_array($status, [403, 429], true) || str_contains($t, 'MYMEMORY WARNING')) {
                Cache::put('chat_translation_down', true, now()->addMinutes(10));
            }
            return null;
        }
        return $t;
    }

    protected function google(string $text, string $from, string $to): ?string
    {
        $res = Http::connectTimeout(3)->timeout(6)->asJson()
            ->post('https://translation.googleapis.com/language/translate/v2?key=' . urlencode((string) config('flashpay.translation.google_key')), [
                'q' => $text, 'source' => $this->code($from, 'google'), 'target' => $this->code($to, 'google'), 'format' => 'text',
            ]);
        $res->throw();
        return $res->json('data.translations.0.translatedText');
    }

    protected function deepl(string $text, string $from, string $to): ?string
    {
        $key = (string) config('flashpay.translation.deepl_key');
        $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $target = ['EN' => 'EN-GB', 'PT' => 'PT-PT'][$this->code($to, 'deepl')] ?? $this->code($to, 'deepl');
        $res = Http::connectTimeout(3)->timeout(6)->withHeaders(['Authorization' => 'DeepL-Auth-Key ' . $key])
            ->asForm()->post($host . '/v2/translate', ['text' => $text, 'source_lang' => $this->code($from, 'deepl'), 'target_lang' => $target]);
        $res->throw();
        return $res->json('translations.0.text');
    }
}
