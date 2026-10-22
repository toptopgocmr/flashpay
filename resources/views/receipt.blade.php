<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Reçu OfficePay {{ $r['reference'] }}</title>
@include('partials.receipt-style')
</head>
<body>
<div class="wrap">
  @include('partials.receipt-card', ['r' => $r])
  <div class="actions">
    <button onclick="window.print()">Imprimer / enregistrer en PDF</button>
    <button class="alt" onclick="document.body.classList.toggle('ticket'); this.textContent = document.body.classList.contains('ticket') ? 'Format A4' : 'Format ticket (58/80 mm)'">Format ticket (58/80 mm)</button>
  </div>
</div>
<script>if (location.hash === '#ticket') document.body.classList.add('ticket');</script>
</body>
</html>
