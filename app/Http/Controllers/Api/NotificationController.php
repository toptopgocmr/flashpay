<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

/** Centre de notifications in-app (§11) — historique consultable par profil. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $q = AppNotification::where('user_id', $request->user()->id)->latest('id');
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }
        // Surveillance par l'app (son + pop-up) : seulement les notifications plus récentes que after_id
        if ($after = (int) $request->query('after_id')) {
            $q->where('id', '>', $after);
        }
        return response()->json($q->paginate(30) ->toArray() + ['unread' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    /** Détail d'une notification (marquée lue) + récapitulatif complet de l'opération liée. */
    public function show(Request $request, AppNotification $notification)
    {
        $user = $request->user();
        abort_unless($notification->user_id === $user->id, 404);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        $payload = $notification->toArray();
        $txId = (int) (($notification->data ?? [])['transaction_id'] ?? 0);
        $tx = $txId ? \App\Models\Transaction::with('sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone', 'initiator:id,full_name,phone')->find($txId) : null;
        if ($tx && $tx->concerns($user)) {
            $j = \App\Support\TransactionPresenter::present($tx, \App\Support\TransactionPresenter::walletIdsOf($user), (int) $user->id);
            $payload['transaction'] = $tx->only(['id', 'reference', 'type', 'status', 'amount', 'fee', 'currency', 'destination_amount', 'destination_currency', 'created_at', 'completed_at'])
                + $j + ['status_label' => $tx->statusLabel()];
        }

        return response()->json($payload);
    }

    public function markRead(Request $request, AppNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);
        return response()->json($notification);
    }

    public function markAllRead(Request $request)
    {
        $n = AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['updated' => $n]);
    }

    // ---------------------------------------------------------- Console admin

    public function admin(Request $request)
    {
        $q = AppNotification::where('audience', 'admin')->latest('id');
        if ($request->input('kind') === 'payments') {
            $q->where('type', 'like', 'payment\_%');
        } elseif ($request->input('kind') === 'others') {
            $q->where('type', 'not like', 'payment\_%');
        }
        if ($request->filled('severity')) {
            $q->where('severity', $request->input('severity'));
        }
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }
        return response()->json($q->paginate(40)->toArray() + [
            'unread' => AppNotification::where('audience', 'admin')->whereNull('read_at')->count(),
            'critical_unread' => AppNotification::where('audience', 'admin')->where('severity', 'critical')->whereNull('read_at')->count(),
        ]);
    }

    public function adminMarkRead(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $q = AppNotification::where('audience', 'admin')->whereNull('read_at');
        if ($ids) {
            $q->whereIn('id', $ids);
        }
        return response()->json(['updated' => $q->update(['read_at' => now()])]);
    }
}
