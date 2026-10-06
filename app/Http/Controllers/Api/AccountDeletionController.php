<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Client\SupportService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Suppression de compte demandée depuis l'application (exigence Google Play).
 *
 * La demande ouvre un ticket prioritaire traité par l'équipe : solde à zéro,
 * aucune opération en cours, puis fermeture du compte. Les données de
 * transaction sont conservées le temps imposé par la réglementation (COBAC / LBC-FT).
 */
class AccountDeletionController extends Controller
{
    public function store(Request $request, SupportService $support)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $user = $request->user();

        $open = SupportTicket::where('user_id', $user->id)
            ->where('category', 'account')->where('subject', 'like', 'Suppression de compte%')
            ->whereNotIn('status', ['closed', 'resolved'])->first();
        if ($open) {
            return response()->json(['reference' => $open->reference, 'already' => true,
                'message' => "Votre demande {$open->reference} est déjà en cours de traitement."]);
        }

        $balance = (int) ($user->wallet?->balance ?? 0);
        $body = "Le client demande la suppression de son compte FlashPay.\n"
            . 'Solde du wallet au moment de la demande : ' . number_format($balance, 0, ',', ' ') . ' ' . ($user->wallet?->currency ?? 'XAF') . "\n"
            . 'Motif : ' . (trim((string) ($data['reason'] ?? '')) ?: 'non précisé');

        $t = $support->openTicket($user, 'account', 'Suppression de compte', $body, 'high');
        Audit::log('account.deletion_requested', $user, ['ticket' => $t->reference]);

        return response()->json([
            'reference' => $t->reference,
            'message' => $balance > 0
                ? "Demande {$t->reference} enregistrée. Votre solde de " . number_format($balance, 0, ',', ' ') . ' doit d\'abord être retiré ou transféré : notre équipe vous contactera sous 72 h.'
                : "Demande {$t->reference} enregistrée. Votre compte sera fermé sous 72 h ; vous recevrez une confirmation.",
        ], 201);
    }
}
