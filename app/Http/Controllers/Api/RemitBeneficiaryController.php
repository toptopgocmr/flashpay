<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitBeneficiary;
use App\Services\Remit\RemitBeneficiaryBook;
use Illuminate\Http\Request;

/** /api/pay/international/beneficiaries — carnet des bénéficiaires internationaux. */
class RemitBeneficiaryController extends Controller
{
    public function __construct(protected RemitBeneficiaryBook $book)
    {
    }

    public function index(Request $request)
    {
        $q = RemitBeneficiary::where('user_id', $request->user()->id);
        if ($c = strtoupper((string) $request->query('country'))) {
            $q->where('country', $c);
        }
        $list = $q->orderByDesc('favorite')->orderByDesc('last_used_at')->limit(100)->get();

        return response()->json(['beneficiaries' => $list->map->toOwnerArray()->values()]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'label' => 'nullable|string|max:120',
            'country' => 'required|string|size:2',
            'method' => 'required|in:wallet,bank,cash',
            'business_type' => 'nullable|in:p2p,p2b,b2p,b2b',
            'service' => 'nullable|array',
            'beneficiary' => 'required|array',
            'account' => 'nullable|array',
            'why' => 'nullable|array',
        ]);
        $b = $this->book->remember($request->user(), $v, $v['label'] ?? null);

        return response()->json(['message' => 'Bénéficiaire enregistré.', 'beneficiary' => $b->toOwnerArray()], 201);
    }

    public function update(Request $request, RemitBeneficiary $beneficiary)
    {
        abort_unless($beneficiary->user_id === $request->user()->id, 404);
        $v = $request->validate(['label' => 'nullable|string|min:2|max:120', 'favorite' => 'nullable|boolean']);
        $beneficiary->update(array_filter($v, fn ($x) => $x !== null));

        return response()->json(['beneficiary' => $beneficiary->fresh()->toOwnerArray()]);
    }

    public function destroy(Request $request, RemitBeneficiary $beneficiary)
    {
        abort_unless($beneficiary->user_id === $request->user()->id, 404);
        $beneficiary->delete();

        return response()->json(['deleted' => true]);
    }
}
