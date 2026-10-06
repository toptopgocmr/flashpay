<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\CommissionRule;
use App\Models\FloatRequest;
use App\Models\FraudAlert;
use App\Models\MerchantApiKey;
use App\Models\MiniProgram;
use App\Models\PaymentIntent;
use App\Models\ReconciliationReport;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookDelivery;
use App\Services\Agent\CommissionService;
use App\Services\Agent\FloatRequestService;
use App\Services\Ops\PlatformSettings;
use App\Services\Ops\ReconciliationService;
use App\Services\Ops\WalletAdjustmentService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Back-office (§3.4, §11.4, §12, §13, §14) : approvisionnements, interventions
 * exceptionnelles, anti-fraude, journal d'audit, réconciliation, mode dégradé,
 * plafonds, barème de commissions, super-agents, mini-programmes, API e-commerce.
 */
class AdminOpsController extends Controller
{
    // ------------------------------------------------ Approvisionnements agents

    public function floatRequests(Request $request)
    {
        $q = FloatRequest::with('agent.user:id,full_name,phone', 'superAgent.user:id,full_name', 'reviewer:id,full_name')->latest();
        $request->filled('status') ? $q->where('status', $request->input('status')) : $q->where('status', 'pending');
        if ($request->filled('agent_id')) {
            $q->where('agent_id', $request->input('agent_id'));
        }
        return response()->json($q->paginate(30));
    }

    public function reviewFloatRequest(Request $request, FloatRequest $floatRequest, FloatRequestService $service)
    {
        $v = $request->validate(['decision' => 'required|in:approve,reject', 'amount' => 'nullable|integer|min:100', 'reason' => 'required_if:decision,reject|nullable|string|max:200']);
        return response()->json($v['decision'] === 'approve'
            ? $service->approve($floatRequest, $request->user(), $v['amount'] ?? null)
            : $service->reject($floatRequest, $request->user(), $v['reason']));
    }

    /** Super-agent : activation et rattachement de sous-agents (§3.1.2). */
    public function setSuperAgent(Request $request, Agent $agent)
    {
        $v = $request->validate(['is_super_agent' => 'nullable|boolean', 'parent_agent_id' => 'nullable|integer|exists:agents,id', 'low_float_threshold' => 'nullable|integer|min:0']);
        if (array_key_exists('parent_agent_id', $v) && $v['parent_agent_id']) {
            abort_if((int) $v['parent_agent_id'] === $agent->id || ! Agent::find($v['parent_agent_id'])->is_super_agent, 422, 'Le parent doit être un super-agent.');
        }
        $agent->update(array_intersect_key($v, array_flip(['is_super_agent', 'parent_agent_id', 'low_float_threshold'])));
        Audit::log('agent.hierarchy', $agent, $v);
        return response()->json($agent->fresh('parent.user:id,full_name'));
    }

    // ------------------------------------------------ Interventions exceptionnelles (§3.4.3)

    public function adjustWallet(Request $request, User $user, WalletAdjustmentService $service)
    {
        $wallet = $user->wallet ?? abort(422, 'Aucun wallet pour ce compte.');
        $v = $request->validate(['direction' => 'required|in:credit,debit', 'amount' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:190']);
        return response()->json($service->adjust($wallet, $v['direction'], $v['amount'], $v['reason'], $request->user()), 201);
    }

    // ------------------------------------------------ Anti-fraude

    public function fraudAlerts(Request $request)
    {
        $q = FraudAlert::with('user:id,full_name,phone,blocked_until,risk_score', 'transaction:id,reference,amount,currency')->latest();
        $q->where('status', $request->input('status', 'open'));
        return response()->json($q->paginate(30));
    }

    public function reviewFraudAlert(Request $request, FraudAlert $alert)
    {
        $v = $request->validate(['decision' => 'required|in:cleared,confirmed', 'unblock' => 'nullable|boolean', 'note' => 'nullable|string|max:190']);
        $alert->update(['status' => $v['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'details' => ($alert->details ?? []) + ['review_note' => $v['note'] ?? null]]);
        if (($v['unblock'] ?? false) && $alert->user) {
            $alert->user->forceFill(['blocked_until' => null, 'risk_score' => $v['decision'] === 'cleared' ? max(0, $alert->user->risk_score - 20) : $alert->user->risk_score])->save();
        }
        Audit::log('fraud.review', $alert, $v);
        return response()->json($alert->fresh('user'));
    }

    public function unblockUser(Request $request, User $user)
    {
        $v = $request->validate(['reason' => 'required|string|max:190']);
        $user->forceFill(['blocked_until' => null, 'lost_reported_at' => null, 'pin_locked_until' => null, 'pin_attempts' => 0])->save();
        Audit::log('user.unblock', $user, $v);
        return response()->json(['message' => 'Compte débloqué.']);
    }

    // ------------------------------------------------ Journal d'audit

    public function auditLogs(Request $request)
    {
        $q = AuditLog::with('actor:id,full_name')->latest('id');
        foreach (['action', 'subject_type', 'subject_id', 'actor_id'] as $f) {
            if ($request->filled($f)) {
                $f === 'action' ? $q->where('action', 'like', $request->input($f) . '%') : $q->where($f, $request->input($f));
            }
        }
        return response()->json($q->paginate(50));
    }

    /** Vérifie l'intégrité de la chaîne de hachage du journal. */
    public function verifyAuditChain()
    {
        $prev = '';
        $broken = null;
        AuditLog::orderBy('id')->chunk(500, function ($logs) use (&$prev, &$broken) {
            foreach ($logs as $l) {
                $h = hash('sha256', $prev . '|' . $l->action . '|' . $l->subject_type . '|' . $l->subject_id . '|' . json_encode($l->data) . '|' . $l->actor_id . '|' . $l->created_at->toIso8601String());
                if ($h !== $l->hash) {
                    $broken = $l->id;
                    return false;
                }
                $prev = $l->hash;
            }
        });
        return response()->json(['intact' => $broken === null, 'first_broken_id' => $broken, 'entries' => AuditLog::count()]);
    }

    // ------------------------------------------------ Réconciliation (§13.1)

    public function reconciliation()
    {
        return response()->json(ReconciliationReport::latest('id')->limit(30)->get());
    }

    public function runReconciliation(ReconciliationService $service)
    {
        return response()->json($service->run(), 201);
    }

    // ------------------------------------------------ Paramètres : mode dégradé et plafonds (§12, §14)

    public function settings(PlatformSettings $settings)
    {
        return response()->json([
            'channels' => $settings->channels(),
            'limits' => ['config' => config('limits'), 'overrides' => $settings->get('limits', [])],
            'security' => array_intersect_key(config('security'), array_flip(['require_otp_on_register', 'pin_mandatory', 'device_policy', 'otp_on_new_device', 'cash_in_client_confirmation', 'refund_window_days', 'dynamic_qr_ttl', 'rto_minutes', 'rpo_minutes'])),
        ]);
    }

    public function updateChannels(Request $request, PlatformSettings $settings)
    {
        $v = $request->validate(['channels' => 'required|array', 'channels.*.enabled' => 'required|boolean', 'channels.*.message' => 'nullable|string|max:250']);
        $current = $settings->get('channels', []);
        foreach ($v['channels'] as $k => $c) {
            if (isset(PlatformSettings::CHANNELS[$k])) {
                $current[$k] = ['enabled' => $c['enabled'], 'message' => $c['message'] ?? null];
            }
        }
        $settings->set('channels', $current, $request->user()->id);
        Audit::log('settings.channels', null, $current);
        $off = collect($current)->filter(fn ($c) => ! $c['enabled'])->keys()->implode(', ');
        app(\App\Services\Notifications\NotificationService::class)->toAdmins('degraded_mode', $off ? "Mode dégradé actif : {$off}" : 'Tous les canaux sont rétablis', null, ['severity' => $off ? 'warning' : 'success']);
        return response()->json($settings->channels());
    }

    /** Tarifs des partenaires (PEEX, WacePay…) utilisés quand le partenaire ne renvoie pas ses frais. */
    public function partnerFees()
    {
        return response()->json(\App\Support\PartnerFees::rates());
    }

    /** Tarifs contractuels par défaut (ex. offre WacePay), pour le bouton « Rétablir » de la console. */
    public function partnerFeeDefaults()
    {
        return response()->json(\App\Support\PartnerFees::DEFAULTS);
    }

    public function updatePartnerFees(Request $request, PlatformSettings $settings)
    {
        $v = $request->validate([
            'rates' => 'required|array',
            'rates.*.pct' => 'nullable|numeric|min:0|max:50',
            'rates.*.fixed' => 'nullable|integer|min:0|max:1000000',
            'rates.*.countries' => 'nullable|array|max:60',
            'rates.*.countries.*.pct' => 'nullable|numeric|min:0|max:50',
            'rates.*.countries.*.fixed' => 'nullable|integer|min:0|max:1000000',
        ]);
        $current = \App\Support\PartnerFees::sanitize($v['rates']);
        $settings->set('partner_fees', $current, $request->user()->id);
        Audit::log('settings.partner_fees', null, $current);
        return response()->json(\App\Support\PartnerFees::rates());
    }

    public function updateLimits(Request $request, PlatformSettings $settings)
    {
        $v = $request->validate(['limits' => 'required|array']);
        $settings->set('limits', $v['limits'], $request->user()->id);
        Audit::log('settings.limits', null, $v['limits']);
        return response()->json($settings->get('limits'));
    }

    // ------------------------------------------------ Barème de commissions (§3.1.7)

    public function commissionRules()
    {
        return response()->json(['rules' => CommissionRule::orderBy('operation')->orderBy('min_amount')->get(), 'operations' => CommissionService::OPERATIONS]);
    }

    public function saveCommissionRule(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|integer|exists:commission_rules,id',
            'operation' => 'required|in:' . implode(',', array_keys(CommissionService::OPERATIONS)),
            'min_amount' => 'required|integer|min:0',
            'max_amount' => 'nullable|integer|gt:min_amount',
            'type' => 'required|in:fixed,percent,fee_share',
            'value' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);
        $rule = CommissionRule::updateOrCreate(['id' => $v['id'] ?? null], $v);
        Audit::log('commission_rule.save', $rule, $v);
        return response()->json($rule, 201);
    }

    public function deleteCommissionRule(CommissionRule $rule)
    {
        Audit::log('commission_rule.delete', $rule, $rule->toArray());
        $rule->delete();
        return response()->json(['message' => 'Règle supprimée.']);
    }

    // ------------------------------------------------ Mini-programmes (§3.5.3)

    public function miniPrograms()
    {
        return response()->json(MiniProgram::with('merchant:id,business_name')->orderBy('status')->orderBy('sort')->get());
    }

    public function saveMiniProgram(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|integer|exists:mini_programs,id',
            'merchant_id' => 'required|integer|exists:merchants,id',
            'name' => 'required|string|max:80',
            'category' => 'required|in:recharge,billetterie,services_publics,ecommerce,autre',
            'description' => 'nullable|string|max:255',
            'icon_url' => 'nullable|url|max:255',
            'entry_url' => 'required|url|max:255',
            'status' => 'nullable|in:pending,approved,suspended',
            'sort' => 'nullable|integer|min:0|max:1000',
        ]);
        $mp = MiniProgram::updateOrCreate(['id' => $v['id'] ?? null], $v);
        Audit::log('mini_program.save', $mp, ['status' => $mp->status]);
        return response()->json($mp, 201);
    }

    // ------------------------------------------------ E-commerce (supervision)

    public function ecommerce(Request $request)
    {
        return response()->json([
            'keys' => MerchantApiKey::with('merchant:id,business_name')->where('active', true)->latest()->get()->map(fn ($k) => $k->only(['id', 'merchant_id', 'environment', 'public_key', 'secret_last4', 'webhook_url', 'last_used_at']) + ['merchant' => $k->merchant?->business_name]),
            'intents' => PaymentIntent::with('merchant:id,business_name')->latest()->limit(50)->get(),
            'webhooks_failed' => WebhookDelivery::where('status', 'failed')->latest()->limit(30)->get(),
            'webhooks_pending' => WebhookDelivery::where('status', 'pending')->count(),
        ]);
    }

    // ------------------------------------------------ Suivi des intégrations e-commerce

    public function integrations(\App\Services\Ecommerce\IntegrationStatusService $svc)
    {
        $merchants = \App\Models\Merchant::where(fn ($q) => $q->where('online_payments', true)->orWhereHas('apiKeys'))->with('user:id,full_name,phone')->get();
        $rows = $merchants->map(fn ($m) => $svc->status($m) + ['phone' => $m->user?->phone])->sortBy(fn ($r) => array_search($r['status'], ['in_progress', 'live_pending', 'sandbox_validated', 'not_started', 'live']))->values();
        return response()->json([
            'data' => $rows,
            'counts' => $rows->countBy('status'),
            'steps' => collect(\App\Services\Ecommerce\IntegrationStatusService::STEPS)->map(fn ($s, $k) => ['key' => $k, 'label' => $s[0], 'required' => $s[1], 'help' => $s[2]])->values(),
        ]);
    }

    public function integration(\App\Models\Merchant $merchant, \App\Services\Ecommerce\IntegrationStatusService $svc)
    {
        return response()->json($svc->status($merchant) + [
            'keys' => $merchant->apiKeys()->latest()->get()->map(fn ($k) => $k->only(['id', 'environment', 'public_key', 'secret_last4', 'webhook_url', 'allowed_ips', 'active', 'last_used_at', 'created_at'])),
            'recent_intents' => PaymentIntent::where('merchant_id', $merchant->id)->latest()->limit(20)->get(['id', 'public_id', 'environment', 'amount', 'currency', 'order_reference', 'status', 'failure_reason', 'amount_refunded', 'created_at']),
            'recent_webhooks' => WebhookDelivery::where('merchant_id', $merchant->id)->latest()->limit(20)->get(['id', 'event', 'url', 'status', 'attempts', 'last_response_code', 'last_error', 'delivered_at', 'created_at']),
        ]);
    }

    public function validateIntegration(Request $request, \App\Models\Merchant $merchant, \App\Services\Ecommerce\IntegrationStatusService $svc)
    {
        $v = $request->validate(['action' => 'required|in:validate,revoke', 'note' => 'nullable|string|max:190']);
        $v['action'] === 'validate' ? $svc->validateManually($merchant, $v['note'] ?? null) : $svc->revokeValidation($merchant, $v['note'] ?? null);
        return response()->json($svc->status($merchant->fresh()));
    }

    public function revokeApiKey(MerchantApiKey $key)
    {
        $key->update(['active' => false]);
        Audit::log('api_key.revoke', $key);
        return response()->json(['message' => 'Clé révoquée.']);
    }

    public function retryWebhook(WebhookDelivery $delivery)
    {
        $delivery->update(['status' => 'pending', 'next_attempt_at' => now()]);
        return response()->json(app(\App\Services\Ecommerce\WebhookService::class)->attempt($delivery));
    }
}
