# FlashPay API + Admin (Laravel 11 + Vue 3 intégrée)

Backend Wallet / Ledger / Switch multi-rails, **et** l'interface Super Admin /
Support Vue, servie directement par Laravel sous `/admin` (même projet, même
serveur — plus de projet Vue séparé).

## ⚠️ Étape préalable indispensable : squelette Laravel

Ce dossier contient le **code métier** (app/, routes/, database/migrations,
resources/, config/flashpay.php, bootstrap/app.php) mais pas les fichiers de
base générés par `laravel new` (`artisan`, `public/index.php`,
`config/app.php`, `config/database.php`, etc.), qui n'ont pas pu être
récupérés depuis cet environnement (pas d'accès à Packagist ici).

**À faire une seule fois, en local :**

```bash
# 1. Générer un squelette Laravel 11 vierge à côté
composer create-project laravel/laravel:^11.0 flashpay-skeleton

# 2. Copier NOTRE code métier PAR-DESSUS le squelette (écrase les fichiers
#    équivalents du squelette, garde artisan/public/index.php/config de base)
rsync -av --exclude 'vendor' --exclude 'node_modules' \
  ./ ./flashpay-skeleton/

# 3. Se placer dans le projet fusionné
cd flashpay-skeleton
```

Ou plus simplement : créez le squelette Laravel, puis copiez/collez manuellement
nos dossiers `app/`, `routes/`, `database/`, `resources/`, `config/flashpay.php`,
`bootstrap/app.php`, `.env.example`, `vite.config.js`, `package.json` par-dessus.

## Installation (dans le projet fusionné)

```bash
composer install
cp .env.example .env
php artisan key:generate

php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
# -> remplace le placeholder database/migrations/2026_01_01_000000_create_permission_tables.php

php artisan migrate --seed        # rôles + tarifs par défaut + super admin

npm install
npm run build                     # build l'admin Vue dans public/build/
# (en dev, lancer "npm run dev" dans un terminal séparé pour le hot-reload)

php artisan serve                 # http://localhost:8000
```

- **API** : `http://localhost:8000/api/...`
- **Admin Vue** : `http://localhost:8000/admin` (login Super Admin/Support)

Identifiants Super Admin par défaut (à changer immédiatement) :
- Téléphone : `242060000000`
- Mot de passe : `ChangeMoi123!`

## Comment l'admin est servi

- `routes/web.php` : une route catch-all `GET /admin/{any?}` renvoie
  `resources/views/admin.blade.php`, qui charge les assets via `@vite(...)`.
- `resources/js/admin/` : sources Vue (App.vue, router, vues, store Pinia).
  Le routeur Vue utilise `createWebHistory('/admin')` pour rester cohérent
  avec le préfixe servi par Laravel.
- `resources/css/admin.css` : styles (palette FlashPay).
- `vite.config.js` + `package.json` (racine du projet) : intégration
  standard `laravel-vite-plugin`. L'API (`/api/*`) n'est jamais interceptée
  par la route catch-all de l'admin.
- Auth : token Sanctum stocké en `localStorage` côté Vue (inchangé), même
  si l'admin est maintenant même origine — donc pas de souci CORS.

## Configuration des rails de paiement

Voir `config/flashpay.php` et les variables `.env` correspondantes :

| Rail | Statut livré | À faire pour passer en production |
|---|---|---|
| **PEEX (collecte)** | Connecteur HTTP réel câblé (`PeexConnector`) | Confirmer avec PEEX : noms d'endpoints exacts, méthode d'auth, format du payload, algo de signature webhook. Tout est marqué `TODO` dans le fichier. |
| MTN, Airtel, Orange, Moov… | Opérés via PEEX | PEEX est l'unique passerelle de paiement |

## Architecture

- `FlashPay Wallet` → `app/Services/WalletService.php` (comptes & soldes, verrouillage DB)
- `FlashPay Ledger` → `app/Services/LedgerService.php` (double écriture systématique)
- `FlashPay Switch` → `app/Services/SwitchService.php` (orchestration débit/crédit multi-rails)
- Connecteurs → `app/Services/Connectors/*` (un par rail, interface commune `PaymentRailConnector`)

## Rôles (Spatie Permission)

`client`, `merchant`, `agent`, `super_admin`, `support` — un même numéro de
téléphone peut avoir un compte `client` **et** un compte `merchant` séparés
(deux logins distincts dans l'app Flutter), voir `AuthController::login()`
qui accepte un paramètre `profile`.
