# FlashPay — Application Flutter (Client / Marchand / Agent)

Une seule application, **deux comptes séparés** pour Client et Marchand
(même choix produit que pour un numéro de téléphone pouvant avoir un
compte de chaque type côté API) : l'écran d'entrée fait choisir l'espace
à ouvrir, puis affiche le login/inscription correspondant.

## Structure

```
lib/
  config/        thème (charte FlashPay) + constantes (URL API)
  models/        User, Wallet, Merchant, Transaction
  services/      client Dio + Auth/Wallet/Transaction/Merchant services
  providers/     SessionProvider (état global : utilisateur, profil actif)
  widgets/       BalanceCard, QuickAction, TransactionTile
  screens/
    auth/        sélection de profil, login, inscription
    client/      accueil, envoyer, scanner QR, mon QR, historique, profil
    merchant/    tableau de bord, QR marchand, encaissements, retrait, points de vente
```

## Installation

```bash
flutter pub get
flutter run --dart-define=FLASHPAY_API_URL=http://10.0.2.2:8000/api   # émulateur Android
# ou
flutter run --dart-define=FLASHPAY_API_URL=http://localhost:8000/api  # simulateur iOS / web
```

Pour un appareil physique ou la production, pointez `FLASHPAY_API_URL`
vers le domaine HTTPS réel du backend FlashPay.

## Ce qui est déjà branché sur l'API

- Inscription / connexion avec sélection de profil (`client`/`merchant`/`agent`)
- Solde + historique + détail de transaction
- Envoi d'argent P2P, paiement marchand par QR (scan côté client, génération côté marchand)
- Tableau de bord marchand (encaissé aujourd'hui / total), gestion des points de vente
- Demande de retrait marchand vers MTN MoMo / Airtel Money / banque / PEEX

## À compléter avant mise en production

- Paiement **NFC** et **saisie manuelle** (le backend expose déjà `method: nfc|manual`
  sur `/transactions/pay-merchant` — il ne manque que l'UI dédiée, cf. `qr_scan_screen.dart`
  comme modèle).
- Écrans dédiés à l'espace **Agent** (cash-in / cash-out, float) — les endpoints
  existent déjà côté API (`/agent/*`).
- Vérification KYC (upload pièce d'identité) — actuellement le statut `kyc_status`
  est affiché mais aucun flux d'upload n'est implémenté.
- Notifications push (confirmation de transaction en temps réel).
- Icônes/splash natifs (`flutter_launcher_icons`, `flutter_native_splash`) et
  identité visuelle finale (le logo éclair est repris en `Icons.bolt` en attendant
  les assets définitifs dans `assets/images/`).
