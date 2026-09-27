<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activité d'un utilisateur (client, agent…) : ses transactions sont celles
 * où son wallet est source ou destination, ou qu'il a initiées.
 */
class UserActivity
{
    public static function query(User $user): Builder
    {
        $wid = $user->wallet?->id;

        return Transaction::query()->where(function ($w) use ($user, $wid) {
            $w->where('initiated_by', $user->id);
            if ($wid) {
                $w->orWhere('source_wallet_id', $wid)->orWhere('destination_wallet_id', $wid);
            }
        });
    }

    /** Statistiques globales + répartition par canal. */
    public static function stats(User $user): array
    {
        $wid = $user->wallet?->id ?? 0;
        $expr = TransactionChannels::sql();

        $r = self::query($user)->selectRaw("COUNT(*) as c,
            COALESCE(SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END),0) as ok,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END),0) as ko,
            COALESCE(SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END),0) as rj,
            COALESCE(SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END),0) as p,
            COALESCE(SUM(CASE WHEN status = 'successful' AND source_wallet_id = ? THEN amount ELSE 0 END),0) as out_v,
            COALESCE(SUM(CASE WHEN status = 'successful' AND destination_wallet_id = ? THEN amount ELSE 0 END),0) as in_v,
            COALESCE(SUM(CASE WHEN status = 'successful' AND source_wallet_id = ? THEN fee ELSE 0 END),0) as fees,
            MAX(created_at) as last_at, MIN(created_at) as first_at", [$wid, $wid, $wid])->first();

        $byChannel = self::query($user)->selectRaw("{$expr} as ch, COUNT(*) as c, COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END),0) as v")
            ->groupBy('ch')->orderByDesc('c')->get()
            ->map(fn ($x) => ['key' => $x->ch, 'label' => TransactionChannels::LABELS[$x->ch] ?? $x->ch, 'family' => TransactionChannels::familyOf($x->ch), 'count' => (int) $x->c, 'volume' => (int) $x->v]);

        $done = $r->ok + $r->ko + $r->rj;

        return [
            'count' => (int) $r->c,
            'successful' => (int) $r->ok,
            'failed' => (int) $r->ko,
            'reversed' => (int) $r->rj,
            'processing' => (int) $r->p,
            'success_rate' => $done ? round($r->ok * 100 / $done, 1) : null,
            'volume_out' => (int) $r->out_v,
            'volume_in' => (int) $r->in_v,
            'fees_paid' => (int) $r->fees,
            'first_activity' => $r->first_at,
            'last_activity' => $r->last_at,
            'by_channel' => $byChannel,
        ];
    }

    public static function recent(User $user, int $limit = 15)
    {
        $expr = TransactionChannels::sql();
        return self::query($user)->latest()->limit($limit)
            ->select('id', 'reference', 'type', 'source_rail', 'destination_rail', 'source_wallet_id', 'destination_wallet_id', 'source_account', 'destination_account', 'amount', 'fee', 'currency', 'status', 'created_at')
            ->selectRaw("{$expr} as channel")->get()
            ->map(function ($t) use ($user) {
                $t->channel_label = TransactionChannels::LABELS[$t->channel] ?? $t->channel;
                $t->direction = $t->source_wallet_id && $t->source_wallet_id === $user->wallet?->id ? 'out' : 'in';
                return $t;
            });
    }
}
