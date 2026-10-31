<?php

namespace App\Console\Commands;

use App\Services\Client\GiftService;
use App\Services\Ecommerce\PaymentIntentService;
use App\Services\Ecommerce\WebhookService;
use App\Services\Merchant\PaymentRequestService;
use App\Services\Peex\PendingTimeoutService;
use Illuminate\Console\Command;

/** Expirations (QR dynamiques, liens, intents e-commerce, cadeaux) et nouvel essai des webhooks. */
class FlashpayMaintenanceCommand extends Command
{
    protected $signature = 'flashpay:maintenance';

    protected $description = 'Expire validations mobile money non confirmées / QR dynamiques / liens / payment intents / cadeaux et relance les webhooks en échec';

    public function handle(PaymentRequestService $requests, PaymentIntentService $intents, GiftService $gifts, WebhookService $webhooks, PendingTimeoutService $timeouts): int
    {
        // Chaque étape est indépendante : une erreur n'empêche plus les autres de tourner
        $steps = [
            'paiement(s) carte non finalisé(s)' => fn () => app(\App\Services\Payments\CardPaymentService::class)->expireAbandoned(),
            'validation(s) mobile money expirée(s)' => fn () => $timeouts->expireDue(),
            'demande(s) expirée(s)' => fn () => $requests->expireDue(),
            'intent(s) expiré(s)' => fn () => $intents->expireDue(),
            'cadeau(x) clôturé(s)' => fn () => $gifts->expireDue(),
            'webhook(s) relancé(s)' => fn () => $webhooks->retryDue(),
        ];
        $parts = [];
        $failed = false;
        foreach ($steps as $label => $fn) {
            try {
                $parts[] = $fn() . ' ' . $label;
            } catch (\Throwable $e) {
                $failed = true;
                $parts[] = 'ERREUR ' . $label;
                \Illuminate\Support\Facades\Log::error('flashpay:maintenance : ' . $label, ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
                $this->error($label . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            }
        }
        $this->info(implode(', ', $parts) . '.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
