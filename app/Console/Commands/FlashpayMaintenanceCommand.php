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
        $this->info(sprintf(
            '%d validation(s) mobile money expirée(s), %d demande(s) expirée(s), %d intent(s) expiré(s), %d cadeau(x) clôturé(s), %d webhook(s) relancé(s).',
            $timeouts->expireDue(), $requests->expireDue(), $intents->expireDue(), $gifts->expireDue(), $webhooks->retryDue(),
        ));
        return self::SUCCESS;
    }
}
