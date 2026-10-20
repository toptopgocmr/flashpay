@php $fmt = fn ($n) => number_format((int) $n, 0, ',', ' '); $S = \App\Support\ReceiptSeal::class; @endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Vérification d'un reçu FlashPay</title>
@include('partials.receipt-style')
<style>
  .verdict { display: flex; gap: 12px; align-items: center; margin: 18px 22px 6px; padding: 14px; border-radius: 14px; }
  .verdict svg { width: 34px; height: 34px; flex: none; }
  .verdict b { display: block; font-size: 17px; }
  .verdict small { color: inherit; opacity: .85; }
  .verdict.ok { background: #ecfdf5; color: #065f46; }
  .verdict.ko { background: #fef2f2; color: #991b1b; }
  .note { margin: 6px 22px 14px; font-size: 13px; color: #475569; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head">
      <div class="brand"><img src="{{ asset('images/flashpay-logo.png') }}" alt="" class="logo"> Flash<span>Pay</span></div>
      <small>Vérification de reçu</small>
    </div>
    @if ($valid)
      <div class="verdict ok">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>
        <div><b>Reçu authentique</b><small>Émis par FlashPay · N° de contrôle {{ $r['seal_code'] }}</small></div>
      </div>
      <p class="note">Comparez avec le reçu présenté : <b>montant, date, référence et bénéficiaire doivent être identiques</b>. Sinon, le document a été modifié.</p>
      <table>
        <tr><td>Référence</td><td class="mono">{{ $r['reference'] }}</td></tr>
        <tr><td>Statut actuel</td><td><span class="status s-{{ $r['status_code'] }}">{{ $r['status'] }}</span></td></tr>
        <tr><td>Type</td><td>{{ $r['type'] }}</td></tr>
        <tr><td>Date</td><td>{{ $r['date'] }}</td></tr>
        <tr><td>Expéditeur</td><td>{{ $S::shortName($r['sender']) ?? '—' }}@if($r['sender_account'])<br><span class="mono">{{ $S::mask($r['sender_account']) }}</span>@endif</td></tr>
        <tr><td>Bénéficiaire</td><td>{{ $S::shortName($r['beneficiary']) ?? '—' }}@if($r['beneficiary_account'])<br><span class="mono">{{ $S::mask($r['beneficiary_account']) }}</span>@endif</td></tr>
        <tr><td>Montant</td><td>{{ $fmt($r['amount']) }} {{ $r['currency'] }}</td></tr>
        <tr><td>Frais FlashPay</td><td>{{ $fmt($r['fee']) }} {{ $r['currency'] }}</td></tr>
        <tr class="total"><td>Total débité</td><td>{{ $fmt($r['total']) }} {{ $r['currency'] }}</td></tr>
        @if ($r['received_currency'] !== $r['currency'] || $r['received'] !== $r['amount'])
          <tr><td>Montant reçu</td><td>{{ $fmt($r['received']) }} {{ $r['received_currency'] }}</td></tr>
        @endif
      </table>
    @else
      <div class="verdict ko">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/></svg>
        <div><b>Reçu non reconnu</b><small>Référence {{ $reference }}</small></div>
      </div>
      <p class="note">Aucun reçu FlashPay ne correspond à cette référence et à ce code de contrôle. Le document présenté n'est pas un original : n'acceptez pas ce paiement sans confirmation du service client FlashPay.</p>
    @endif
    <div class="foot">Vérifié le {{ $checked_at }} · FlashPay · Brazzaville, République du Congo</div>
  </div>
</div>
</body>
</html>
