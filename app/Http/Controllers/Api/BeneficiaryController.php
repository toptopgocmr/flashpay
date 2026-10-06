<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedBeneficiary;
use Illuminate\Http\Request;

/** Carnet de bénéficiaires (enregistrés automatiquement à chaque envoi). */
class BeneficiaryController extends Controller
{
    public function index(Request $request)
    {
        $q = SavedBeneficiary::where('user_id', $request->user()->id);
        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D/', '', $term);
            $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")));
        }
        if ($c = strtoupper((string) $request->query('country'))) {
            $q->where('country', $c);
        }

        return response()->json($q->orderByDesc('favorite')->orderByDesc('last_used_at')->limit(100)->get());
    }

    public function update(Request $request, SavedBeneficiary $beneficiary)
    {
        abort_unless($beneficiary->user_id === $request->user()->id, 404);
        $v = $request->validate(['name' => 'nullable|string|min:2|max:120', 'favorite' => 'nullable|boolean']);
        $beneficiary->update(array_filter($v, fn ($x) => $x !== null));

        return response()->json($beneficiary->fresh());
    }

    public function destroy(Request $request, SavedBeneficiary $beneficiary)
    {
        abort_unless($beneficiary->user_id === $request->user()->id, 404);
        $beneficiary->delete();

        return response()->json(['deleted' => true]);
    }
}
