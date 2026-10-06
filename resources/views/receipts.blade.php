<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Reçus FlashPay ({{ count($receipts) }})</title>
@include('partials.receipt-style')
</head>
<body>
<div class="wrap">
  <div class="actions">
    <button onclick="window.print()">Imprimer les {{ count($receipts) }} reçus</button>
    <button class="alt" onclick="document.body.classList.toggle('ticket'); this.textContent = document.body.classList.contains('ticket') ? 'Format A4' : 'Format ticket (58/80 mm)'">Format ticket (58/80 mm)</button>
  </div>
  @forelse ($receipts as $r)
    @include('partials.receipt-card', ['r' => $r])
  @empty
    <p style="text-align:center;color:#64748b">Aucune transaction.</p>
  @endforelse
</div>
@if (request()->boolean('autoprint'))<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>@endif
</body>
</html>
