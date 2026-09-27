<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $wallet = $request->user()->wallet;
        return response()->json($wallet);
    }

    public function rates(Request $request)
    {
        // Frais / taux applicables — cf. §2 "Consulter les taux et frais applicables"
        $tariffs = \App\Models\Tariff::where('active', true)->get();
        return response()->json($tariffs);
    }
}
