<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ops\PlatformSettings;

/**
 * Point de contrôle unique appelé par le PaymentGateway (SwitchService) avant
 * toute création de transaction : canal disponible (mode dégradé), compte non
 * bloqué, anti-fraude, plafonds KYC côté débit et solde max côté crédit.
 */
class ComplianceGuard
{
    public function __construct(
        protected LimitService $limits,
        protected FraudService $fraud,
        protected PlatformSettings $settings,
    ) {
    }

    public function check(array $p): void
    {
        $type = $p['type'] ?? null;
        $this->channels($p);

        if (($p['source_rail'] ?? null) === 'wallet' && ! empty($p['source_wallet_id'])) {
            $wallet = Wallet::with('user')->find($p['source_wallet_id']);
            if ($wallet && $wallet->user) {
                if (! in_array($type, LimitService::EXEMPT_TYPES, true)) {
                    $this->fraud->evaluate($wallet->user, (int) $p['amount'], (string) $type, $p['currency'] ?? null);
                }
                $this->limits->assertOutgoing($wallet->user, $wallet, (int) $p['amount'] + (int) ($p['fee'] ?? 0), $p['scope'] ?? null, $type);
            }
        } elseif (! empty($p['initiated_by']) && ($u = User::find($p['initiated_by']))) {
            $this->fraud->assertNotBlocked($u);
        }

        if (($p['destination_rail'] ?? null) === 'wallet' && ! empty($p['destination_wallet_id'])) {
            $wallet = Wallet::with('user')->find($p['destination_wallet_id']);
            if ($wallet) {
                $this->limits->assertIncoming($wallet, (int) ($p['destination_amount'] ?? $p['amount']), $type);
            }
        }
    }

    public function assertChannel(string $channel): void
    {
        if (! $this->settings->channelEnabled($channel)) {
            throw new BusinessException($this->settings->channelMessage($channel), 'channel_unavailable', 503, ['channel' => $channel]);
        }
    }

    protected function channels(array $p): void
    {
        if (($p['source_rail'] ?? null) === 'peex') {
            $this->assertChannel('peex_collect');
        }
        if (($p['destination_rail'] ?? null) === 'peex') {
            $this->assertChannel('peex_payout');
        }
        if (($p['source_rail'] ?? null) === 'card') {
            $this->assertChannel('card');
        }
        if (($p['meta']['channel'] ?? null) === 'agent') {
            $this->assertChannel('agents');
        }
    }
}
