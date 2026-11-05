<?php

namespace App\Services\Stripe;

use App\Models\Payout;
use Stripe\StripeClient;

/**
 * Trasferimenti verso i conti Connect dei venditori. Isolato come ConnectService e PaymentService:
 * nei test si sostituisce, in sviluppo punta a stripe-mock (STRIPE_API_BASE).
 */
class TransferService
{
    private function client(): StripeClient
    {
        $config = ['api_key' => config('toogood.stripe.secret')];
        if ($base = config('toogood.stripe.api_base')) {
            $config['api_base'] = $base;
        }

        return new StripeClient($config);
    }

    /** Invia il guadagno al venditore e restituisce l'id del trasferimento. */
    public function send(Payout $payout, string $accountId): string
    {
        $transfer = $this->client()->transfers->create([
            'amount' => $payout->amount_cents,
            'currency' => config('toogood.payments.currency'),
            'destination' => $accountId,
            'transfer_group' => 'order_'.$payout->order_id,
            'description' => 'Ordine '.$payout->order->number(),
            'metadata' => ['order_id' => (string) $payout->order_id, 'payout_id' => (string) $payout->id],
        // Stessa chiave = stesso trasferimento: se il comando si ripete dopo un errore di rete, non si paga due volte
        ], ['idempotency_key' => 'payout-'.$payout->id]);

        return $transfer->id;
    }
}
