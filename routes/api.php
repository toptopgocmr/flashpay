<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgentAdminController;
use App\Http\Controllers\Api\ClientAdminController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PeexAdminController;
use App\Http\Controllers\Api\PricingAdminController;
use App\Http\Controllers\Api\PeexController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\AdminOpsController;
use App\Http\Controllers\Api\ClientFeaturesController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MerchantToolsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentRequestController;
use App\Http\Controllers\Api\SupportCenterController;
use App\Http\Controllers\Api\RolesAdminController;
use App\Http\Controllers\Api\MoneyRequestController;
use App\Http\Controllers\Api\V1\PaymentIntentApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — FlashPay
|--------------------------------------------------------------------------
*/

// --- Public ---
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/auth/otp', [AuthController::class, 'requestOtp'])->middleware('throttle:6,1');
Route::post('/auth/pin/reset', [AuthController::class, 'resetPin'])->middleware('throttle:6,1');
Route::post('/auth/report-lost', [AuthController::class, 'reportLost'])->middleware('throttle:6,1');
// État des services (mode dégradé, §14) — bandeau d'information dans l'app
Route::get('/status', function (\App\Services\Ops\PlatformSettings $s) {
    return response()->json(['channels' => $s->channels(), 'rto_minutes' => config('security.rto_minutes'), 'rpo_minutes' => config('security.rpo_minutes'), 'server_time' => now()->toIso8601String()]);
});
Route::get('/support/faq', [SupportCenterController::class, 'faq']);

// --- API e-commerce v1 (§4.7) : clé secrète marchand, sandbox / production ---
Route::prefix('v1')->middleware('merchant.api')->group(function () {
    Route::post('/payment-intents', [PaymentIntentApiController::class, 'create'])->middleware('idempotent:required');
    Route::get('/payment-intents/{id}', [PaymentIntentApiController::class, 'show']);
    Route::post('/payment-intents/{id}/confirm', [PaymentIntentApiController::class, 'confirm']);
    Route::post('/payment-intents/{id}/cancel', [PaymentIntentApiController::class, 'cancel']);
    Route::post('/refunds', [PaymentIntentApiController::class, 'refund'])->middleware('idempotent:required');
    Route::get('/refunds/{id}', [PaymentIntentApiController::class, 'showRefund']);
});
// Callbacks PEEX (Basic Auth) — une URL par service, + URL générique
Route::post('/webhooks/peex/{service?}', [WebhookController::class, 'peex'])
    ->where('service', 'collect|disbursement|remittance')
    ->name('webhooks.peex');

// Page de paiement carte simulée (FLASHPAY_CARD_DRIVER=sandbox)
Route::get('/card-checkout/{token}', [\App\Http\Controllers\CardCheckoutController::class, 'show']);
Route::post('/card-checkout/{token}', [\App\Http\Controllers\CardCheckoutController::class, 'submit'])->middleware('throttle:30,1');

// Banque partenaire : retrait GAB avec code FlashPay (header X-Partner-Key)
Route::prefix('partners/atm')->middleware('throttle:60,1')->group(function () {
    Route::post('/verify', [CashController::class, 'atmVerify']);
    Route::post('/redeem', [CashController::class, 'atmRedeem']);
});

// --- Authentifié (tous profils) ---
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // --- Sécurité & profil (§4.5, §15) ---
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/pin', [AuthController::class, 'setPin'])->middleware('throttle:10,1');
    Route::post('/me/pin/verify', [AuthController::class, 'verifyPin'])->middleware('throttle:10,1');
    Route::get('/me/devices', [AuthController::class, 'devices']);
    Route::post('/me/devices', [AuthController::class, 'updateDevice']);
    Route::delete('/me/devices/{device}', [AuthController::class, 'revokeDevice']);

    // --- KYC (§3.3.1, §12) ---
    Route::get('/kyc', [KycController::class, 'show']);
    Route::get('/kyc/documents/{document}/file', [KycController::class, 'myFile']);
    Route::get('/me/photo', [KycController::class, 'myPhoto']);
    Route::post('/kyc/documents', [KycController::class, 'upload'])->middleware(['throttle:20,1', 'cap:kyc']);

    // --- Notifications (§11) ---
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // --- Support, litiges (§13.2, §16) ---
    Route::get('/support/tickets', [SupportCenterController::class, 'tickets']);
    Route::post('/support/tickets', [SupportCenterController::class, 'openTicket'])->middleware('throttle:10,1');
    Route::post('/support/tickets/{ticket}/messages', [SupportCenterController::class, 'replyTicket']);
    Route::get('/disputes', [SupportCenterController::class, 'disputes']);
    Route::post('/disputes', [SupportCenterController::class, 'openDispute'])->middleware('throttle:10,1');
    Route::get('/disputes/received', [SupportCenterController::class, 'receivedDisputes']);
    Route::post('/disputes/{dispute}/refund', [SupportCenterController::class, 'counterpartyRefund'])->whereNumber('dispute')->middleware(['pin', 'idempotent']);
    Route::post('/disputes/{dispute}/respond', [SupportCenterController::class, 'counterpartyRespond'])->whereNumber('dispute');

    // --- QR dynamique / lien de paiement / NFC / e-commerce côté payeur ---
    Route::get('/pay/requests/{token}', [PaymentRequestController::class, 'show']);
    Route::post('/pay/requests/{token}', [PaymentRequestController::class, 'pay'])->middleware(['cap:pay', 'pin', 'idempotent']);
    Route::get('/pay/intents', [PaymentRequestController::class, 'pendingIntents']);
    Route::get('/pay/intents/{publicId}', [PaymentRequestController::class, 'showIntent']);
    Route::post('/pay/intents/{publicId}', [PaymentRequestController::class, 'payIntent'])->middleware(['cap:pay', 'pin', 'idempotent']);

    // --- Wallet consolidé, comptes liés, cadeaux, partage de note, mini-programmes ---
    Route::get('/wallet/overview', [ClientFeaturesController::class, 'overview']);
    Route::get('/linked-accounts', [ClientFeaturesController::class, 'linkedAccounts']);
    Route::post('/linked-accounts', [ClientFeaturesController::class, 'addLinkedAccount']);
    Route::delete('/linked-accounts/{account}', [ClientFeaturesController::class, 'deleteLinkedAccount']);
    Route::post('/linked-accounts/{account}/default', [ClientFeaturesController::class, 'defaultLinkedAccount']);
    Route::post('/pay/withdraw-bank', [ClientFeaturesController::class, 'withdrawToBank'])->middleware(['cap:withdraw', 'pin', 'idempotent']);
    Route::get('/gifts', [ClientFeaturesController::class, 'gifts']);
    Route::post('/gifts', [ClientFeaturesController::class, 'sendGift'])->middleware(['cap:send', 'pin', 'idempotent']);
    Route::get('/gifts/{code}', [ClientFeaturesController::class, 'showGift']);
    Route::post('/gifts/{code}/claim', [ClientFeaturesController::class, 'claimGift'])->middleware('throttle:20,1');
    Route::get('/splits', [ClientFeaturesController::class, 'splits']);
    Route::post('/splits', [ClientFeaturesController::class, 'createSplit']);
    Route::post('/splits/{split}/remind', [ClientFeaturesController::class, 'remindSplit']);
    Route::post('/splits/{split}/cancel', [ClientFeaturesController::class, 'cancelSplit']);
    Route::post('/splits/shares/{share}/pay', [ClientFeaturesController::class, 'paySplitShare'])->middleware(['cap:send', 'pin', 'idempotent']);
    Route::post('/splits/shares/{share}/decline', [ClientFeaturesController::class, 'declineSplitShare']);
    Route::get('/mini-programs', [ClientFeaturesController::class, 'miniPrograms']);
    // Demandes d'argent entre utilisateurs (« Scanner un ami pour lui demander »)
    Route::get('/money-requests', [MoneyRequestController::class, 'index']);
    Route::post('/money-requests', [MoneyRequestController::class, 'store'])->middleware(['throttle:20,1', 'cap:request']);
    Route::get('/money-requests/{moneyRequest}', [MoneyRequestController::class, 'show'])->whereNumber('moneyRequest');
    Route::post('/money-requests/{moneyRequest}/pay', [MoneyRequestController::class, 'pay'])->whereNumber('moneyRequest')->middleware(['cap:send', 'pin', 'idempotent']);
    Route::post('/money-requests/{moneyRequest}/decline', [MoneyRequestController::class, 'decline'])->whereNumber('moneyRequest');
    Route::post('/money-requests/{moneyRequest}/cancel', [MoneyRequestController::class, 'cancel'])->whereNumber('moneyRequest');
    Route::post('/money-requests/{moneyRequest}/remind', [MoneyRequestController::class, 'remind'])->whereNumber('moneyRequest');

    // --- Encaissement : marchand principal ET caissiers (§3.2.2, §3.2.3) ---
    Route::middleware('role:merchant,cashier')->prefix('merchant')->group(function () {
        Route::post('/payment-requests', [MerchantToolsController::class, 'createRequest'])->middleware('cap:collect');
        Route::get('/payment-requests', [MerchantToolsController::class, 'listRequests']);
        Route::get('/payment-requests/{token}', [MerchantToolsController::class, 'showRequest']);
        Route::post('/payment-requests/{token}/cancel', [MerchantToolsController::class, 'cancelRequest']);
        Route::get('/cashier/collections', [MerchantToolsController::class, 'cashierCollections']);
    });

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/rates', [WalletController::class, 'rates']);

    // --- Paiements interopérables (style Wave) — tous profils ---
    Route::prefix('pay')->group(function () {
        Route::get('/corridors', [PaymentController::class, 'corridors']);
        Route::post('/lookup', [PaymentController::class, 'lookup']);
        Route::post('/quote', [PaymentController::class, 'quote']);
        Route::post('/transfer', [PaymentController::class, 'transfer'])->middleware(['cap:send', 'pin', 'idempotent']);
        Route::post('/deposit', [PaymentController::class, 'deposit'])->middleware(['cap:topup', 'idempotent']);
        Route::post('/withdraw', [PaymentController::class, 'withdraw'])->middleware(['cap:withdraw,settlement', 'pin', 'idempotent']);
        Route::get('/merchant/{code}', [PaymentController::class, 'merchantInfo'])->where('code', '.*');
        Route::post('/merchant', [PaymentController::class, 'payMerchant'])->middleware(['cap:pay', 'pin', 'idempotent']);
        Route::get('/transactions/{transaction}/status', [PaymentController::class, 'status']);
        // Moyens proposés selon le pays + code QR de paiement + bons de retrait
        Route::get('/methods', [CashController::class, 'methods']);
        Route::post('/code', [CashController::class, 'payCode'])->middleware('throttle:30,1');
        Route::get('/vouchers', [CashController::class, 'vouchers']);
        Route::post('/vouchers', [CashController::class, 'createVoucher'])->middleware(['cap:withdraw,settlement', 'pin', 'idempotent']);
        Route::post('/vouchers/{voucher}/cancel', [CashController::class, 'cancelVoucher']);
    });

    // --- PEEX : corridors & frais (tous profils) ---
    Route::get('/peex/corridors', [PeexController::class, 'corridors']);
    Route::get('/peex/fees', [PeexController::class, 'fees']);
    Route::post('/peex/resolve-phone', [PeexController::class, 'resolvePhone']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::get('/transactions/{transaction}/receipt', [\App\Http\Controllers\Api\ReceiptController::class, 'link']);

    // Messagerie entre utilisateurs (texte, photos, vidéos courtes)
    Route::get('/chats', [\App\Http\Controllers\Api\ChatController::class, 'index']);
    Route::post('/chats', [\App\Http\Controllers\Api\ChatController::class, 'open'])->middleware('throttle:30,1');
    Route::get('/chats/{conversation}/messages', [\App\Http\Controllers\Api\ChatController::class, 'messages'])->whereNumber('conversation');
    Route::post('/chats/{conversation}/messages', [\App\Http\Controllers\Api\ChatController::class, 'send'])->whereNumber('conversation')->middleware('throttle:60,1');
    Route::get('/chats/messages/{message}/file', [\App\Http\Controllers\Api\ChatController::class, 'file'])->whereNumber('message');
    Route::get('/chats/messages/{message}/link', [\App\Http\Controllers\Api\ChatController::class, 'link'])->whereNumber('message');

    // --- Client ---
    Route::middleware('role:client')->group(function () {
        Route::post('/transactions/send-money', [TransactionController::class, 'sendMoney'])->middleware(['cap:send', 'pin', 'idempotent']);
        Route::post('/transactions/pay-merchant', [TransactionController::class, 'payMerchant'])->middleware(['cap:pay', 'pin', 'idempotent']);
        // Recharge du wallet depuis MTN / Airtel (collecte PEEX)
        Route::post('/transactions/cash-in-mobile', [PeexController::class, 'cashInMobile'])->middleware('cap:topup');
        // Transfert mobile money -> mobile money (ex: MTN CG -> Airtel CG) via PEEX
        Route::post('/transactions/mobile-transfer', [PeexController::class, 'mobileTransfer'])->middleware(['cap:send', 'pin', 'idempotent']);
    });

    // --- Marchand ---
    Route::middleware('role:merchant')->prefix('merchant')->group(function () {
        Route::get('/dashboard', [MerchantController::class, 'dashboard']);
        Route::get('/outlets', [MerchantController::class, 'outlets']);
        Route::post('/outlets', [MerchantController::class, 'createOutlet']);
        Route::get('/collections', [MerchantController::class, 'collections']);
        Route::post('/withdraw', [MerchantController::class, 'requestWithdrawal'])->middleware(['cap:settlement', 'pin', 'idempotent']);
        Route::post('/collect-ussd', [MerchantController::class, 'collectUssd'])->middleware('cap:collect');
        Route::post('/charge-code', [CashController::class, 'chargeCode'])->middleware(['throttle:30,1', 'cap:scan_client']);
        // Règlements : comptes (mobile money, banque, wallet, cash), règlement manuel / automatique
        Route::get('/settlement', [SettlementController::class, 'show']);
        Route::post('/settlement/accounts', [SettlementController::class, 'addAccount']);
        Route::post('/settlement/accounts/{account}/default', [SettlementController::class, 'setDefault']);
        Route::delete('/settlement/accounts/{account}', [SettlementController::class, 'deleteAccount']);
        Route::post('/settlement/settle', [SettlementController::class, 'settle'])->middleware(['throttle:20,1', 'cap:settlement', 'pin', 'idempotent']);
        Route::post('/settlement/auto', [SettlementController::class, 'auto'])->middleware('cap:settlement');
        Route::get('/qr', [MerchantController::class, 'qr']);
        // Caissiers, remboursements, rapports, paiement en ligne (§3.2.3–3.2.5, §13.2)
        Route::get('/cashiers', [MerchantToolsController::class, 'cashiers']);
        Route::post('/cashiers', [MerchantToolsController::class, 'createCashier'])->middleware('cap:cashiers');
        Route::post('/cashiers/{cashier}', [MerchantToolsController::class, 'updateCashier'])->middleware('cap:cashiers');
        Route::get('/refunds', [MerchantToolsController::class, 'refunds']);
        Route::post('/refunds', [MerchantToolsController::class, 'refund'])->middleware(['cap:reports', 'pin', 'idempotent']);
        Route::get('/reports', [MerchantToolsController::class, 'reports'])->middleware('cap:reports');
        Route::get('/statement', [MerchantToolsController::class, 'statement'])->middleware('cap:reports');
        Route::get('/api-keys', [MerchantToolsController::class, 'apiKeys']);
        Route::post('/api-keys', [MerchantToolsController::class, 'issueApiKeys'])->middleware('pin');
        Route::post('/api-keys/{key}', [MerchantToolsController::class, 'updateApiKey']);
        Route::get('/payment-intents', [MerchantToolsController::class, 'intents']);
    });

    // --- Agent ---
    Route::middleware('role:agent')->prefix('agent')->group(function () {
        Route::get('/dashboard', [AgentController::class, 'dashboard']);
        Route::post('/cash-in', [CashController::class, 'agentCashIn'])->middleware(['cap:cash_in', 'pin', 'idempotent']);
        Route::get('/vouchers/{code}', [CashController::class, 'agentVoucher'])->middleware(['throttle:20,1', 'cap:cash_out']);
        Route::post('/vouchers/redeem', [CashController::class, 'agentRedeem'])->middleware(['throttle:20,1', 'cap:cash_out']);
        Route::post('/cash-out', [AgentController::class, 'cashOut'])->middleware('cap:cash_out');
        Route::get('/history', [AgentController::class, 'history']);
        // Approvisionnement, caisse, commissions (§3.1.2, §3.1.6, §3.1.7)
        Route::get('/float-requests', [AgentController::class, 'floatRequests']);
        Route::post('/float-requests', [AgentController::class, 'createFloatRequest'])->middleware('cap:float');
        Route::post('/float-requests/{floatRequest}/cancel', [AgentController::class, 'cancelFloatRequest']);
        Route::post('/float-requests/{floatRequest}/review', [AgentController::class, 'reviewFloatRequest'])->middleware(['cap:sub_agents', 'pin']);
        Route::get('/float', [AgentController::class, 'float']);
        Route::get('/journal', [AgentController::class, 'journal'])->middleware('cap:reports');
        Route::get('/reconciliation', [AgentController::class, 'reconciliation'])->middleware('cap:reports');
        Route::get('/commissions', [AgentController::class, 'commissions']);
    });

    // --- Support / Opérations ---
    Route::middleware(['role:support,super_admin', 'cap:desk'])->prefix('support')->group(function () {
        Route::get('/transactions/{transaction}', [SupportController::class, 'transactionDetail']);
        Route::get('/transactions/{transaction}/trace', [SupportController::class, 'transactionTrace']);
        Route::post('/transactions/{transaction}/notes', [SupportController::class, 'addNote']);
        Route::post('/transactions/{transaction}/escalate', [SupportController::class, 'escalate']);
        Route::post('/reset-password', [SupportController::class, 'resetPassword']);
        // Tickets, litiges, KYC (§13.2, §16)
        Route::get('/desk/tickets', [SupportCenterController::class, 'adminTickets']);
        Route::post('/desk/tickets/{ticket}/reply', [SupportCenterController::class, 'adminReplyTicket']);
        Route::get('/desk/disputes', [SupportCenterController::class, 'adminDisputes']);
        Route::get('/desk/kyc', [KycController::class, 'queue']);
        Route::get('/desk/kyc/users/{user}', [KycController::class, 'userDocuments']);
        Route::get('/desk/kyc/documents/{document}/file', [KycController::class, 'file']);
        Route::get('/desk/kyc/users/{user}/photo', [KycController::class, 'userPhoto']);
        Route::post('/desk/kyc/documents/{document}/request-resend', [KycController::class, 'requestResend']);
    });

    // --- Super Admin ---
    Route::middleware('role:super_admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', AdminDashboardController::class);
        Route::get('/transactions', [AdminDashboardController::class, 'transactions']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'createInternalUser']);

        // Comptes : activation / désactivation (unitaire, sélection ou tous)
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::post('/accounts/bulk-status', [AccountController::class, 'bulkStatus']);
        Route::post('/accounts/{user}/status', [AccountController::class, 'setStatus']);

        // Gestion des clients
        Route::get('/clients', [ClientAdminController::class, 'index']);
        // Les clients s'inscrivent eux-mêmes dans l'app : le Super Admin ne crée ni ne modifie
        // un compte client (réinitialisation du mot de passe et activation / désactivation uniquement).
        Route::get('/clients/{user}', [ClientAdminController::class, 'show']);
        Route::post('/clients/{user}/kyc', [ClientAdminController::class, 'kyc']);
        Route::post('/wallets/{user}/status', [ClientAdminController::class, 'walletStatus']);

        // Gestion des agents (fiche + modification)
        Route::get('/agents/{agent}', [AgentAdminController::class, 'show'])->whereNumber('agent');
        Route::put('/agents/{agent}', [AgentAdminController::class, 'update'])->whereNumber('agent');

        Route::get('/badges', [AdminController::class, 'badges']);

        // Rôles & habilitations (maquettes v2) et supervision des caissiers
        Route::get('/roles', [RolesAdminController::class, 'index']);
        Route::put('/roles/{role}/grants/{capability}', [RolesAdminController::class, 'setGrant'])->where(['role' => '[a-z_]+', 'capability' => '[a-z_]+']);
        Route::post('/roles/reset', [RolesAdminController::class, 'reset']);
        Route::get('/cashiers', [RolesAdminController::class, 'cashiers']);
        Route::post('/cashiers/{cashier}/status', [RolesAdminController::class, 'cashierStatus'])->whereNumber('cashier');
        Route::get('/geo', [AdminController::class, 'geo']);
        Route::get('/settlements/bank', [SettlementController::class, 'bankQueue']);
        Route::post('/settlements/{transaction}/complete', [SettlementController::class, 'completeBank']);
        Route::post('/settlements/{transaction}/reject', [SettlementController::class, 'rejectBank']);
        Route::get('/merchants', [AdminController::class, 'merchants']);
        Route::post('/merchants', [AdminController::class, 'createMerchant']);
        Route::get('/merchants/{merchant}/accounts', [AdminController::class, 'merchantAccounts']);
        Route::post('/merchants/{merchant}/accounts', [AdminController::class, 'addMerchantAccount']);
        Route::post('/merchants/{merchant}/accounts/{account}/default', [AdminController::class, 'defaultMerchantAccount']);
        Route::delete('/merchants/{merchant}/accounts/{account}', [AdminController::class, 'deleteMerchantAccount']);
        Route::post('/agents', [AdminController::class, 'createAgent']);
        Route::post('/agents/{agent}/float', [AdminController::class, 'fundAgent']);
        Route::post('/users/{user}/password', [AdminController::class, 'resetPassword']);
        Route::get('/agents', [AdminController::class, 'agents']);
        Route::get('/merchants/pending', [AdminController::class, 'pendingMerchants']);
        Route::post('/merchants/{merchant}/validate', [AdminController::class, 'validateMerchant']);

        Route::get('/agents/pending', [AdminController::class, 'pendingAgents']);
        Route::post('/agents/{agent}/validate', [AdminController::class, 'validateAgent']);

        Route::get('/tariffs', [PricingAdminController::class, 'tariffs']);
        Route::post('/tariffs/{tariff}/active', [PricingAdminController::class, 'toggleTariff']);
        Route::get('/corridors', [PricingAdminController::class, 'corridors']);
        Route::post('/corridors/{iso}', [PricingAdminController::class, 'updateCorridor'])->where('iso', '[A-Za-z]{2}');
        Route::delete('/corridors/{iso}', [PricingAdminController::class, 'resetCorridor'])->where('iso', '[A-Za-z]{2}');
        Route::post('/fx-rates/{rate}/active', [PricingAdminController::class, 'toggleRate']);
        Route::delete('/fx-rates/{rate}', [PricingAdminController::class, 'deleteRate']);
        Route::post('/tariffs', [AdminController::class, 'upsertTariff']);
        Route::delete('/tariffs/{tariff}', [AdminController::class, 'deleteTariff']);
        Route::get('/fx-rates', [AdminController::class, 'fxRates']);
        Route::post('/fx-rates', [AdminController::class, 'upsertFxRate']);

        Route::get('/ledger/overview', [AdminController::class, 'ledgerOverview']);
        Route::get('/reports/activity', [AdminController::class, 'activityReport']);

        // --- PEEX sandbox / supervision ---
        Route::get('/peex/overview', [PeexAdminController::class, 'overview']);
        Route::get('/peex/balances', [PeexAdminController::class, 'balances']);
        Route::get('/peex/requests', [PeexAdminController::class, 'requests']);
        Route::post('/peex/test', [PeexAdminController::class, 'test']);
        Route::post('/peex/requests/{peexRequest}/refresh', [PeexAdminController::class, 'refresh']);
        Route::post('/peex/sync', [PeexAdminController::class, 'sync']);
        Route::post('/peex/simulate-callback', [PeexAdminController::class, 'simulateCallback']);

        // --- Cahier des charges v1.5 ---
        Route::get('/notifications', [NotificationController::class, 'admin']);
        Route::post('/notifications/read', [NotificationController::class, 'adminMarkRead']);
        Route::post('/kyc/documents/{document}/review', [KycController::class, 'review']);
        Route::post('/disputes/{dispute}', [SupportCenterController::class, 'adminUpdateDispute']);
        Route::get('/float-requests', [AdminOpsController::class, 'floatRequests']);
        Route::post('/float-requests/{floatRequest}/review', [AdminOpsController::class, 'reviewFloatRequest']);
        Route::post('/agents/{agent}/hierarchy', [AdminOpsController::class, 'setSuperAgent'])->whereNumber('agent');
        Route::post('/users/{user}/wallet/adjust', [AdminOpsController::class, 'adjustWallet']);
        Route::get('/fraud-alerts', [AdminOpsController::class, 'fraudAlerts']);
        Route::post('/fraud-alerts/{alert}', [AdminOpsController::class, 'reviewFraudAlert']);
        Route::post('/users/{user}/unblock', [AdminOpsController::class, 'unblockUser']);
        Route::get('/audit-logs', [AdminOpsController::class, 'auditLogs']);
        Route::get('/audit-logs/verify', [AdminOpsController::class, 'verifyAuditChain']);
        Route::get('/reconciliation', [AdminOpsController::class, 'reconciliation']);
        Route::post('/reconciliation/run', [AdminOpsController::class, 'runReconciliation']);
        Route::get('/settings', [AdminOpsController::class, 'settings']);
        Route::post('/settings/channels', [AdminOpsController::class, 'updateChannels']);
        Route::post('/settings/limits', [AdminOpsController::class, 'updateLimits']);
        Route::get('/commission-rules', [AdminOpsController::class, 'commissionRules']);
        Route::post('/commission-rules', [AdminOpsController::class, 'saveCommissionRule']);
        Route::delete('/commission-rules/{rule}', [AdminOpsController::class, 'deleteCommissionRule']);
        Route::get('/mini-programs', [AdminOpsController::class, 'miniPrograms']);
        Route::post('/mini-programs', [AdminOpsController::class, 'saveMiniProgram']);
        Route::get('/ecommerce', [AdminOpsController::class, 'ecommerce']);
        Route::post('/api-keys/{key}/revoke', [AdminOpsController::class, 'revokeApiKey']);
        Route::get('/ecommerce/integrations', [AdminOpsController::class, 'integrations']);
        Route::get('/ecommerce/integrations/{merchant}', [AdminOpsController::class, 'integration']);
        Route::post('/ecommerce/integrations/{merchant}', [AdminOpsController::class, 'validateIntegration']);
        Route::post('/webhooks/{delivery}/retry', [AdminOpsController::class, 'retryWebhook']);
    });
});
