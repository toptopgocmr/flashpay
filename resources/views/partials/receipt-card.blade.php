@php $fmt = fn ($n) => number_format((int) $n, 0, ',', ' '); @endphp
<div class="sheet">
  <div class="card">
    <div class="head">
      <div class="brand"><img src="{{ asset('images/flashpay-logo.png') }}" alt="" class="logo"> Office<span>Pay</span></div>
      <small>Reçu de transaction</small>
    </div>
    <div class="hero">
      <span class="status s-{{ $r['status_code'] }}">{{ $r['status'] }}</span>
      <div class="amount">{{ $fmt($r['received']) }} {{ $r['received_currency'] }}</div>
      <div class="type">{{ $r['type'] }}</div>
    </div>
    @if (! empty($r['agent']))
      <div class="agent">Opération effectuée chez l'agent {{ $r['agent'] }}</div>
    @endif
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
      <tr><td>Frais OfficePay</td><td>{{ $fmt($r['fee']) }} {{ $r['currency'] }}</td></tr>
      <tr class="total"><td>Total débité</td><td>{{ $fmt($r['total']) }} {{ $r['currency'] }}</td></tr>
      @if ($r['operator_ref'])<tr><td>Réf. opérateur</td><td class="mono">{{ $r['operator_ref'] }}</td></tr>@endif
      @if ($r['note'])<tr><td>Motif</td><td>{{ $r['note'] }}</td></tr>@endif
    </table>
    @if (! empty($r['seal_code']))
    <div class="seal">
      {!! $r['guilloche'] !!}
      <div class="seal-micro" aria-hidden="true">{{ str_repeat('OFFICEPAY · ORIGINAL · ' . $r['reference'] . ' · ', 6) }}</div>
      <div class="seal-body">
        <div class="seal-qr">{!! $r['qr_svg'] !!}</div>
        <div class="seal-txt">
          <div class="seal-title">ORIGINAL</div>
          <div class="seal-sub">Reçu authentique OfficePay</div>
          <div class="seal-k">N° de contrôle</div>
          <div class="seal-code">{{ $r['seal_code'] }}</div>
          <div class="seal-k">Émis le {{ $r['issued'] }}</div>
        </div>
      </div>
      <div class="seal-micro b" aria-hidden="true">{{ str_repeat('OFFICEPAY · ORIGINAL · ' . $r['seal_code'] . ' · ', 6) }}</div>
      <div class="seal-hint">Scannez le QR code pour vérifier ce reçu en ligne : les informations affichées doivent être identiques.</div>
    </div>
    @endif
    <div class="foot">
      OfficePay · Brazzaville, République du Congo<br>
      Conservez ce reçu. En cas de réclamation, indiquez la référence {{ $r['reference'] }}.
    </div>
  </div>
</div>
