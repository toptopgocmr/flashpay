# FlashPay — paiements interopérables (style Wave)

Un client de n'importe quel opérateur couvert par PEEX (MTN, Airtel, Orange, Moov, M-Pesa…)
peut payer un marchand d'un autre opérateur ou d'un autre pays, recevoir de l'argent sur son
wallet ou directement sur son mobile, et retirer vers son mobile money.

## Zones couvertes (`config/corridors.php`)

| Zone | Pays | Devise |
|---|---|---|
| CEMAC | Congo, Cameroun, Gabon, Tchad, Centrafrique, Guinée équatoriale* | XAF |
| UEMOA | Sénégal, Côte d'Ivoire, Mali, Burkina Faso, Bénin, Togo, Niger, Guinée-Bissau* | XOF |
| RDC | RD Congo | CDF |
| Guinée | Guinée | GNF |

\* Guinée équatoriale fermée (collecte et versement), Guinée-Bissau fermée en collecte.
Surcharge par `.env` : `PEEX_SN_COLLECT=false`, `PEEX_CD_PAYOUT=false`, `PEEX_CG_PAYOUT_API=remittance`…

Un corridor n'est réellement ouvert que s'il est **aussi activé sur votre compte PEEX**
(à demander à support@peexit.com). Les préfixes opérateurs sont indicatifs : si un numéro ne
correspond à aucun préfixe, FlashPay interroge PEEX (`clients/verify_phoneNumber`).

## Zones de frais

- **national** : même pays
- **régional** : même zone (CEMAC ↔ CEMAC, UEMOA ↔ UEMOA)
- **international** : zones différentes (CEMAC ↔ UEMOA, RDC, Guinée)

Grille par défaut (style Wave, modifiable dans Console > Grille tarifaire) :

| Opération | National | Régional | International |
|---|---|---|---|
| Envoi d'argent (client) | 1 % | 2 % | 3 % |
| Paiement marchand (client) | gratuit | gratuit | gratuit |
| Commission marchand | 1 % | 1 % | 1 % |
| Dépôt / recharge | gratuit | gratuit | gratuit |
| Retrait vers mobile money | gratuit | gratuit | gratuit |

## Change

- XAF ↔ XOF : parité fixe 1 : 1 (même ancrage à l'euro), sans marge.
- CDF, GNF, USD : table `exchange_rates`, éditable dans Console > Corridors & change.
  Les taux fournis au démarrage sont **indicatifs** : mettez-les à jour avant toute opération réelle.
- Le montant converti est transmis à PEEX dans la devise du bénéficiaire avec `fxrate = 1`
  (exigence de la doc PEEX). ⚠ À confirmer avec PEEX pour CDF / GNF.

## Parcours

| Parcours | API | Détail |
|---|---|---|
| Envoyer | `POST /api/pay/transfer` | source `wallet` ou `mobile` ; vers un utilisateur FlashPay (wallet, instantané) ou n'importe quel numéro couvert (versement PEEX) |
| Payer un marchand (QR) | `POST /api/pay/merchant` | QR `flashpay://pay?m=FPM-…`, depuis le wallet ou le mobile money du client |
| Encaisser sans app (USSD) | `POST /api/merchant/collect-ussd` | le marchand saisit le numéro du client, qui valide sur son téléphone |
| Recharger | `POST /api/pay/deposit` | mobile money → wallet |
| Retirer | `POST /api/pay/withdraw` | wallet → mobile money (marchand : `POST /api/merchant/withdraw`) |
| Devis | `POST /api/pay/quote` | frais, zone, change, montant reçu, disponibilité |
| Suivi | `GET /api/pay/transactions/{id}/status` | à interroger toutes les 3 s tant que `status = processing` |

Réponses : `201` réussi, `202` en attente (validation USSD ou versement opérateur), `422` échec.

## Mise à jour d'une base existante

```bash
php artisan migrate
php artisan db:seed
```

La migration `2026_09_24_000001_interop_regional` ajoute les zones de frais, les taux de change,
les montants / devises reçus et la commission marchand. Le seeder remplace les 3 anciens tarifs
par défaut par la grille style Wave (les tarifs que vous avez modifiés sont conservés).

## Moyens par pays, QR et retraits cash (`config/payment_methods.php`)

L'app appelle `GET /api/pay/methods?country=CG&operation=deposit|withdraw|pay` : quand le client
change de pays, les moyens proposés changent (opérateurs mobile money du pays, agents, GAB).
Un moyen pas encore ouvert est renvoyé avec `available=false` et s'affiche « Bientôt disponible ».

| Opération | Moyen | Ouverture |
|---|---|---|
| Recharger | Mobile money (tous les opérateurs du pays via PEEX) | `collect` du pays (corridors) |
| Recharger | Espèces chez un agent : le client montre son code QR, l'agent le scanne | `FLASHPAY_AGENT_COUNTRIES` |
| Retirer | Vers mobile money (tous les opérateurs du pays via PEEX) | `payout` du pays |
| Retirer | Cash pickup : code à 10 chiffres + QR, remis chez un agent | `FLASHPAY_AGENT_COUNTRIES` |
| Retirer | GAB sans carte : code à 12 chiffres, banque partenaire | `FLASHPAY_ATM_COUNTRIES` (vide = bientôt) |
| Payer | Scanner le QR du marchand | partout |
| Payer | Montrer son code de paiement (style Alipay), le marchand le scanne | partout |
| Payer | Mobile money vers un marchand FlashPay | `collect` du pays |

- **Code de paiement** (`POST /api/pay/code`) : 18 chiffres commençant par 88, QR `flashpay://code?c=…`,
  valable 2 min, usage unique. Marchand : `POST /api/merchant/charge-code {code, amount}`.
  Agent : `POST /api/agent/cash-in {client_code, amount}`.
- **Bons de retrait** (`POST /api/pay/vouchers {channel: cash_pickup|atm, amount, country}`) : le wallet
  est débité (montant + frais) vers `flashpay:vouchers`. Agent : `GET /api/agent/vouchers/{code}` puis
  `POST /api/agent/vouchers/redeem` (float reconstitué + 50 % des frais). GAB : la banque appelle
  `POST /api/partners/atm/verify|redeem` avec `X-Partner-Key`. Annulation client ou expiration (72 h,
  `php artisan vouchers:expire` toutes les 10 min) : remboursement intégral.
- Tarifs `cash_pickup` et `atm` : 1 % national, 1,5 % régional (Console > Grille tarifaire).
- Tests : `php vendor/bin/phpunit` (tests/Feature/CashNetworkTest.php, 7 scénarios).

## Carte Visa / Mastercard, sans contact (NFC / TPE), envoi multi-sources

- **Carte** (`FLASHPAY_CARD_DRIVER`) : recharge (`POST /api/pay/deposit {method: card}`) et envoi payé par carte
  (`POST /api/pay/transfer {source: card}`). La transaction attend `awaiting_card` et renvoie `checkout_url`
  (page de paiement hébergée, 3-D Secure) : l'app n'a jamais le numéro de carte. En sandbox, page simulée
  `/api/card-checkout/{token}` (4242 4242 4242 4242 acceptée, 4000 0000 0000 0002 refusée). Frais `card` : 2,5 %.
  Paiements carte non finalisés : annulés après 30 min (`vouchers:expire`). En production : brancher le PSP carte.
- **NFC / TPE** : le tag NFC (ou la puce du TPE) contient `flashpay://pay?m=FPM-…` ; le client approche son
  téléphone, l'app ouvre le paiement (`method: nfc`, canal « TPE / NFC »). Le marchand programme son tag depuis
  « Mon QR » → « Activer le sans contact ».
- **Envoyer** : source wallet / mobile money / carte ; le bénéficiaire reçoit sur son wallet FlashPay, son mobile
  money (tout opérateur couvert) ou un compte bancaire (`FLASHPAY_BANK_COUNTRIES`, vide = « bientôt »).

## Règlements marchands (`SettlementService`)

- Comptes de règlement multiples (`settlement_accounts`) : mobile money (tout opérateur / pays couvert, via PEEX),
  compte bancaire (RIB/IBAN), wallet FlashPay (instantané), retrait cash chez un agent (code). Un compte par défaut.
- App marchand → « Règlements » : régler n'importe quel montant vers le compte choisi, gérer les comptes,
  règlement automatique quotidien / hebdomadaire (`php artisan merchants:settle`, 20 h) en gardant un minimum.
- Virement bancaire : wallet débité (frais `bank_transfer` 0,5 %), file « Règlements » de la console :
  « Virement effectué » (référence bancaire) ou « Rejeter » (remboursement automatique montant + frais).
- Création d'un marchand par l'admin : choix du compte de règlement par défaut dans le formulaire.
