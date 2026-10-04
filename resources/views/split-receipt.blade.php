@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $cur = $s->currency ?: 'XAF';
    $tone = ['open' => '#0972d3', 'settled' => '#037f0c', 'closed' => '#037f0c', 'cancelled' => '#d91515'][$r['status']] ?? '#0972d3';
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Cagnotte FlashPay {{ $r['reference'] }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #eef1f6; font: 15px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; }
  .wrap { max-width: 520px; margin: 20px auto; padding: 0 14px; }
  .card { background: #fff; border-radius: 18px; box-shadow: 0 6px 24px rgba(15, 23, 42, .08); overflow: hidden; }
  .head { background: #1e3a8a; color: #fff; padding: 20px 22px; display: flex; justify-content: space-between; align-items: center; }
  .brand { font-weight: 800; font-size: 20px; display: flex; align-items: center; gap: 10px; }
  .brand span { color: #fca5a5; }
  .logo { width: 38px; height: 38px; background: #fff; border-radius: 10px; padding: 4px; object-fit: contain; }
  .head small { opacity: .8; }
  .hero { text-align: center; padding: 22px 22px 6px; }
  .status { display: inline-block; padding: 3px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; color: {{ $tone }}; background: {{ $tone }}1a; }
  .title { font-size: 20px; font-weight: 800; margin: 10px 0 0; }
  .type { color: #64748b; }
  .amount { font-size: 32px; font-weight: 800; margin: 8px 0 0; letter-spacing: -.02em; }
  .bar { height: 8px; background: #e2e8f0; border-radius: 99px; margin: 10px 22px 0; overflow: hidden; }
  .bar i { display: block; height: 100%; background: #16a34a; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0 4px; }
  td, th { padding: 9px 22px; border-top: 1px solid #eef1f6; vertical-align: top; text-align: left; }
  th { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: .04em; }
  .r { text-align: right; white-space: nowrap; }
  .info td:first-child { color: #64748b; width: 42%; }
  .info td:last-child { text-align: right; font-weight: 600; }
  .muted { color: #64748b; font-size: 12.5px; }
  .ok { color: #037f0c; font-weight: 700; } .wait { color: #b45309; } .no { color: #d91515; }
  .total td { font-weight: 800; font-size: 16px; background: #f8fafc; }
  .msg { margin: 12px 22px 0; padding: 10px 12px; background: #f1f5f9; border-radius: 10px; font-style: italic; }
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
      <div class="brand"><img src="{{ asset('images/flashpay-logo.png') }}" alt="" class="logo"> Flash<span>Pay</span></div>
      <small>Reçu de cagnotte</small>
    </div>
    <div class="hero">
      <span class="status">{{ $r['status_label'] }}</span>
      <div class="title">{{ $s->title }}</div>
      <div class="type">{{ $r['purpose_label'] }} · {{ $r['mode_label'] }}</div>
      <div class="amount">{{ $fmt($r['paid_amount']) }} {{ $cur }}</div>
      <div class="muted">collectés @if ($r['target_amount'] > 0) sur {{ $fmt($r['target_amount']) }} {{ $cur }} @endif · {{ $r['contributors_paid'] }}/{{ $r['contributors_total'] }} contributeur(s)</div>
    </div>
    @if ($r['progress'] !== null)
      <div class="bar"><i style="width: {{ round($r['progress'] * 100) }}%"></i></div>
    @endif
    @if ($s->message)<div class="msg">« {{ $s->message }} »</div>@endif
    <table class="info">
      <tr><td>Référence</td><td>{{ $r['reference'] }}</td></tr>
      <tr><td>Bénéficiaire</td><td>{{ $r['beneficiary']['name'] }}<br><span class="muted">+{{ ltrim((string) $r['beneficiary']['phone'], '+') }}</span></td></tr>
      <tr><td>Organisée par</td><td>{{ $s->creator?->full_name }}</td></tr>
      <tr><td>Créée le</td><td>{{ $r['created'] }}</td></tr>
      @if ($r['closed'])<tr><td>Terminée le</td><td>{{ $r['closed'] }}</td></tr>@endif
      @if ($r['self_amount'] > 0)<tr><td>Part de l'organisateur</td><td>{{ $fmt($r['self_amount']) }} {{ $cur }}</td></tr>@endif
    </table>
    <table>
      <tr><th>Contributeur</th><th>Statut</th><th class="r">Montant</th></tr>
      @foreach ($r['rows'] as $row)
        <tr>
          <td>{{ $row['name'] }}<br><span class="muted">{{ $row['paid_at'] ?? $row['phone'] }}</span></td>
          <td class="{{ ['paid' => 'ok', 'pending' => 'wait'][$row['status']] ?? 'no' }}">{{ $row['status_label'] }}</td>
          <td class="r">{{ $row['amount'] > 0 ? $fmt($row['amount']) . ' ' . $cur : '—' }}</td>
        </tr>
      @endforeach
      <tr class="total"><td colspan="2">Total versé au bénéficiaire</td><td class="r">{{ $fmt($r['paid_amount']) }} {{ $cur }}</td></tr>
    </table>
    <div class="foot">
      FlashPay · Brazzaville, République du Congo<br>
      Chaque contribution a été versée directement sur le wallet du bénéficiaire.
    </div>
  </div>
  <div class="actions"><button onclick="window.print()">Imprimer / enregistrer en PDF</button></div>
</div>
</body>
</html>
