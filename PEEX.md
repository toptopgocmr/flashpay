# Intégration PEEX (sandbox)

Doc officielle : https://peex-api-docs.peexit.com/ — authentification par header `SECRETKEY`.

## Configuration (`backend/.env`)

```dotenv
PEEX_SANDBOX=true
PEEX_BASE_URL=https://sandbox.peexit.com/api/v1/
PEEX_PRODUCTION_URL=https://server.peexit.com/api/v1/
PEEX_SECRET_KEY=<clé sandbox>
PEEX_CALLBACK_USERNAME=peex
PEEX_CALLBACK_PASSWORD=peex_callback
PEEX_DEFAULT_COUNTRY=CG          # pays d'un numéro saisi sans indicatif
PEEX_CG_PAYOUT_API=disbursement  # ou "remittance" selon ce que PEEX active pour le Congo
```

Après modification du `.env` : `php artisan config:clear`.

## API PEEX utilisées

| Besoin | Endpoint PEEX | Code FlashPay |
|---|---|---|
| Collecte (débit MTN/Airtel du payeur) | `POST collection/request_payment` | `PeexConnector::collect()` |
| Décaissement (crédit d'un numéro) | `POST disbursement/request_payment` | `PeexConnector::disburse()` |
| Transfert international (remittance) | `POST clients/request_payment` | `PeexConnector::disburse()` si `payout_api = remittance` |
| Statut | `GET {collection\|disbursement\|clients}/all_requests?track_id=` | `PeexConnector::checkStatus()` |
| Frais de collecte | `GET collection/get_fees` | `GET /api/peex/fees` |
| Vérif. numéro | `POST clients/verify_phoneNumber` | `php artisan peex verify` |
| Comptes / soldes | `GET collection/me`, `disbursement/me`, `clients/me` | `php artisan peex ping` |

## Flux asynchrone

PEEX répond d'abord `new` / `pending` ; le statut final (`paid`, `failed`, `rejected`, `canceled`)
arrive **par callback** ou **par polling**.

```
Transfert MTN CG -> Airtel CG
  1. collect (065...)      -> transaction "processing", stage awaiting_source
  2. PEEX "paid"           -> ledger mtn_momo -> suspense, puis disbursement (055...)
                              -> stage awaiting_destination
  3. PEEX "paid"           -> ledger suspense -> airtel_money, transaction "successful"
  Échec collecte           -> transaction "failed"
  Échec décaissement       -> source wallet : remboursement auto ("reversed")
                              source mobile : fonds en suspense, remboursement manuel (Support)
```

Chaque appel est journalisé dans la table `peex_requests` (payload envoyé, réponse, dernier callback).

## Callbacks (webhooks)

POST en **Basic Auth** (`PEEX_CALLBACK_USERNAME` / `PEEX_CALLBACK_PASSWORD`), corps = tableau de transactions.
URLs à transmettre au support PEEX (support@peexit.com) :

- collecte : `{APP_URL}/api/webhooks/peex/collect`
- décaissement : `{APP_URL}/api/webhooks/peex/disbursement`
- remittance : `{APP_URL}/api/webhooks/peex/remittance`

En local, PEEX ne peut pas joindre `localhost` : utilisez le polling (`php artisan schedule:work`,
lancé automatiquement par `demarrer-flashpay.bat`) ou un tunnel HTTPS (`ngrok http 8000`,
puis `APP_URL=https://xxxx.ngrok-free.app`).

## Tester

### Depuis l'admin
http://localhost:8000/admin/peex — soldes des 3 comptes PEEX, formulaire de test avec préréglages
(MTN CG → Airtel CG, Airtel CG → MTN CG, Congo → Cameroun, Congo → Gabon, numéros de test CM),
suivi des demandes, bouton « Synchroniser », et en sandbox « Simuler payé / échec ».

### En ligne de commande (dans `backend/`)

```bash
php artisan peex ping                                   # clé OK ? comptes activés ? soldes
php artisan peex resolve 065123456                      # -> +242065123456, mtn-cg
php artisan peex fees 065123456 1000
php artisan peex transfer 065123456 055123456 100 --wait=120   # MTN CG -> Airtel CG
php artisan peex transfer 055123456 065123456 100 --wait=120   # Airtel CG -> MTN CG
php artisan peex collect 065123456 100                  # MTN CG -> wallet admin
php artisan peex payout 055123456 100                   # wallet admin -> Airtel CG
php artisan peex transfer 065123456 +237677000001 100   # Congo -> Cameroun
php artisan peex payout +241076566326 100               # -> Gabon (remittance)
php artisan peex status FP-XXXXXXXXXXXX                 # suivi d'une transaction
php artisan peex sandbox                                # matrice des numéros de test PEEX
php artisan peex:sync                                   # polling manuel des statuts
```

### Numéros de test sandbox PEEX (Cameroun, avec ou sans 237)

| Résultat | Numéros |
|---|---|
| paid | 677000001 … 677000005 |
| pending | 699000001 … 699000005 |
| failed | 677100001 … 677100005 |
| rejected | 699100001 … 699100005 |

En sandbox, le montant réellement initié est **fixé à 10 FCFA** quel que soit le montant envoyé.

### API mobile (profil client, token Sanctum)

```
GET  /api/peex/corridors
POST /api/peex/resolve-phone         {phone, country?}
GET  /api/peex/fees?amount=&phone=
POST /api/transactions/cash-in-mobile   {phone, amount}
POST /api/transactions/mobile-transfer  {source_phone, destination_phone, amount, beneficiary_name?}
```
Réponse `202` = en attente de confirmation PEEX, `201` = réussi, `422` = échec.

## Corridors (`config/flashpay.php` → `corridors`)

| Pays | Opérateurs (préfixes) | Collecte | Décaissement |
|---|---|---|---|
| CG Congo | MTN (06), Airtel (05, 04) | oui | oui (`disbursement`) |
| CM Cameroun | MTN (67, 650-654, 68), Orange (69, 655-659) | oui | oui (`disbursement`) |
| GA Gabon | Airtel (074/076/077), Moov (062/065/066) | non | oui (`remittance`) |

Ajouter un pays = ajouter une entrée dans `corridors`.

## ⚠️ À confirmer avec PEEX

- La doc publique indique que **seul le Cameroun est actif** en collecte et décaissement, et les numéros
  de test sandbox sont camerounais. Pour le **Congo (MTN / Airtel)**, demandez à PEEX d'activer les
  corridors CG sur votre compte (sandbox puis production) et, s'ils existent, les numéros de test CG.
  Tant que ce n'est pas fait, un test CG peut être refusé (ex. `400 invalid test number`) : le motif
  s'affiche dans la transaction et dans `peex_requests.message`.
- Quelle API utiliser pour créditer un numéro congolais (`disbursement` ou `remittance`) →
  `PEEX_CG_PAYOUT_API`.
- Les identifiants Basic Auth de production (envoyés par PEEX par mail).
