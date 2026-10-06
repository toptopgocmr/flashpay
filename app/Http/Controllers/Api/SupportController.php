<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Endpoints réservés au profil Support / Opérations (cf. §2).
 */
class SupportController extends Controller
{
    public function transactionDetail(Request $request, Transaction $transaction)
    {
        $transaction->load('ledgerEntries', 'notes.author', 'initiator', 'sourceWallet.user', 'destinationWallet.user', 'peexRequests', 'digitwaceRequests');
        // Parcours des fonds (expéditeur → compte principal FlashPay → bénéficiaire) + frais partenaires / marge (super admin)
        $costs = \App\Support\PartnerFees::of($transaction, null, true);
        $out = $transaction->toArray();
        unset($out['peex_requests'], $out['digitwace_requests']);
        $out['flow'] = $costs['flow'];
        $out['gateway'] = \App\Support\TransactionPresenter::gateway($transaction);
        $out['type_label'] = $transaction->typeLabel();
        $out['status_label'] = $transaction->statusLabel();
        $out['channel_label'] = \App\Support\TransactionPresenter::channel($transaction);
        $out['parties'] = \App\Support\TransactionPresenter::parties($transaction);
        $out['details'] = \App\Support\TransactionPresenter::details($transaction);
        if ($request->user()->hasRole('super_admin')) {
            unset($costs['flow']);
            $out['costs'] = $costs;
        }
        return response()->json($out);
    }

    /**
     * Trace le parcours complet d'une transaction entre rails.
     */
    public function transactionTrace(Request $request, Transaction $transaction)
    {
        return response()->json([
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'steps' => [
                [
                    'stage' => 'source',
                    'rail' => $transaction->source_rail,
                    'account' => $transaction->source_account,
                    'external_ref' => $transaction->source_external_ref,
                ],
                [
                    'stage' => 'flashpay_switch',
                    'ledger_entries' => $transaction->ledgerEntries,
                ],
                [
                    'stage' => 'destination',
                    'rail' => $transaction->destination_rail,
                    'account' => $transaction->destination_account,
                    'external_ref' => $transaction->destination_external_ref,
                ],
            ],
        ]);
    }

    public function addNote(Request $request, Transaction $transaction)
    {
        $validated = $request->validate(['note' => 'required|string|max:2000']);

        $note = $transaction->notes()->create([
            'author_id' => $request->user()->id,
            'note' => $validated['note'],
        ]);

        return response()->json($note, 201);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|exists:users,phone',
            'new_password' => 'required|string|min:4',
        ]);

        $user = User::whereIn('phone', \App\Support\Phone::candidates($validated['phone']))->firstOrFail();
        $user->update(['password' => bcrypt($validated['new_password'])]);

        // TODO: notifier l'utilisateur par SMS une fois le provider SMS branché
        return response()->json(['message' => 'Mot de passe réinitialisé.']);
    }

    /**
     * Escalade un incident de paiement vers le partenaire concerné (cf. §2).
     */
    public function escalate(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'partner' => 'required|in:peex',
            'message' => 'required|string',
        ]);

        // TODO: brancher un vrai canal d'escalade (email/ticketing) par partenaire
        $transaction->notes()->create([
            'author_id' => $request->user()->id,
            'note' => "[ESCALADE -> {$validated['partner']}] " . $validated['message'],
        ]);

        return response()->json(['message' => 'Incident escaladé vers ' . $validated['partner']]);
    }
}
