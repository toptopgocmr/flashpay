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
        return response()->json($q->paginate(30) ->toArray() + ['unread' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
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
