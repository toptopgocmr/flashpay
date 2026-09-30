@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $tone = ['successful' => '#037f0c', 'failed' => '#d91515', 'reversed' => '#b45309'][$r['status_code']] ?? '#0972d3';
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Reçu FlashPay {{ $r['reference'] }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #eef1f6; font: 15px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; }
  .wrap { max-width: 460px; margin: 20px auto; padding: 0 14px; }
  .card { background: #fff; border-radius: 18px; box-shadow: 0 6px 24px rgba(15, 23, 42, .08); overflow: hidden; }
  .head { background: #1e3a8a; color: #fff; padding: 20px 22px; display: flex; justify-content: space-between; align-items: center; }
  .brand { font-weight: 800; font-size: 20px; letter-spacing: -.01em; }
  .brand span { color: #fca5a5; }
  .head small { opacity: .8; }
  .hero { text-align: center; padding: 22px 22px 8px; }
  .status { display: inline-block; padding: 3px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; color: {{ $tone }}; background: {{ $tone }}1a; }
  .amount { font-size: 34px; font-weight: 800; margin: 10px 0 2px; letter-spacing: -.02em; }
  .type { color: #64748b; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0 4px; }
  td { padding: 9px 22px; border-top: 1px solid #eef1f6; vertical-align: top; }
  td:first-child { color: #64748b; width: 42%; }
  td:last-child { text-align: right; font-weight: 600; word-break: break-word; }
  .total td { font-size: 16px; }
  .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }
  .fail { margin: 0 22px 14px; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: #991b1b; font-size: 13px; }
  .foot { padding: 14px 22px 20px; color: #64748b; font-size: 12px; text-align: center; border-top: 1px dashed #cbd5e1; }
  .actions { text-align: center; margin: 16px 0 30px; }
  .actions button { border: 0; background: #1e3a8a; color: #fff; font-weight: 700; padding: 11px 22px; border-radius: 999px; font-size: 15px; cursor: pointer; }
  @media print { body { background: #fff; } .actions { display: none; } .card { box-shadow: none; } .wrap { margin: 0 auto; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head">
      <div class="brand">Flash<span>Pay</span></div>
      <small>Reçu de transaction</small>
    </div>
    <div class="hero">
      <span class="status">{{ $r['status'] }}</span>
      <div class="amount">{{ $fmt($r['received']) }} {{ $r['received_currency'] }}</div>
      <div class="type">{{ $r['type'] }}</div>
    </div>
    @if ($r['failure'])
      <div class="fail">Motif : {{ $r['failure'] }}</div>
    @endif
    <table>
      <tr><td>Référence</td><td class="mono">{{ $r['reference'] }}</td></tr>
      <tr><td>Date</td><td>{{ $r['date'] }}</td></tr>
      @if ($r['completed'] && $r['completed'] !== $r['date'])<tr><td>Finalisée le</td><td>{{ $r['completed'] }}</td></tr>@endif
      @if ($r['sender'] || $r['sender_account'])<tr><td>Expéditeur</td><td>{{ $r['sender'] ?? '—' }}@if($r['sender_account'])<br><span class="mono">{{ $r['sender_account'] }}</span>@endif</td></tr>@endif
      @if ($r['beneficiary'] || $r['beneficiary_account'])<tr><td>Bénéficiaire</td><td>{{ $r['beneficiary'] ?? '—' }}@if($r['beneficiary_account'])<br><span class="mono">{{ $r['beneficiary_account'] }}</span>@endif</td></tr>@endif
      <tr><td>Montant</td><td>{{ $fmt($r['amount']) }} {{ $r['currency'] }}</td></tr>
      <tr><td>Frais FlashPay</td><td>{{ $fmt($r['fee']) }} {{ $r['currency'] }}</td></tr>
      <tr class="total"><td>Total débité</td><td>{{ $fmt($r['total']) }} {{ $r['currency'] }}</td></tr>
      @if ($r['operator_ref'])<tr><td>Réf. opérateur</td><td class="mono">{{ $r['operator_ref'] }}</td></tr>@endif
      @if ($r['note'])<tr><td>Motif</td><td>{{ $r['note'] }}</td></tr>@endif
    </table>
    <div class="foot">
      FlashPay · Brazzaville, République du Congo<br>
      Conservez ce reçu. En cas de réclamation, indiquez la référence {{ $r['reference'] }}.
    </div>
  </div>
  <div class="actions"><button onclick="window.print()">Imprimer / enregistrer en PDF</button></div>
</div>
</body>
</html>
