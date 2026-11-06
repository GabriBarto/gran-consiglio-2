<?php

namespace App\Services\Stripe;

use App\Models\Order;
use Stripe\StripeClient;

/**
 * Pagamenti dei clienti con Stripe. Isolato in una classe, come ConnectService, per sostituirlo nei test
 * e per puntare a stripe-mock in sviluppo (STRIPE_API_BASE) o alle API di test di Stripe (vuoto).
 * I dati della carta non passano mai da qui: l'app li invia direttamente a Stripe con il `client_secret`.
 */
class PaymentService
{
    private function client(): StripeClient
    {
        $config = ['api_key' => config('toogood.stripe.secret')];
        if ($base = config('toogood.stripe.api_base')) {
            $config['api_base'] = $base;
        }

        return new StripeClient($config);
    }

    /**
     * Crea il PaymentIntent dell'ordine. L'incasso va alla piattaforma; il guadagno del venditore parte
     * più tardi (RB-11), per questo si usa un `transfer_group` con l'ordine.
     *
     * @return array{id: string, client_secret: string}
     */
    public function createIntent(Order $order): array
    {
        $intent = $this->client()->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => config('toogood.payments.currency'),
            'automatic_payment_methods' => ['enabled' => true],
            'transfer_group' => 'order_'.$order->id,
            'description' => 'Ordine '.$order->number(),
            'metadata' => ['order_id' => (string) $order->id, 'customer_id' => (string) $order->customer_id],
        ], ['idempotency_key' => 'order-'.$order->id.'-intent']);

        return ['id' => $intent->id, 'client_secret' => (string) $intent->client_secret];
    }

    /** Annulla un pagamento non ancora concluso (ordine scaduto o sostituito da un altro carrello). */
    public function cancelIntent(string $intentId): void
    {
        $this->client()->paymentIntents->cancel($intentId);
    }

    /** Rimborsa per intero un pagamento arrivato quando la prenotazione era già scaduta. Restituisce l'id del rimborso. */
    public function refund(string $intentId): string
    {
        // Con la stessa chiave Stripe restituisce lo stesso rimborso se il webhook viene ripetuto
        return $this->client()->refunds->create(['payment_intent' => $intentId], ['idempotency_key' => 'late-refund-'.$intentId])->id;
    }

    /**
     * Rimborso (anche parziale) deciso dall'admin su un reclamo. La chiave di idempotenza lega il rimborso al
     * reclamo: se la decisione viene ripetuta dopo un errore, Stripe restituisce lo stesso rimborso.
     */
    public function refundAmount(string $intentId, int $cents, string $idempotencyKey): string
    {
        return $this->client()->refunds->create(
            ['payment_intent' => $intentId, 'amount' => $cents, 'metadata' => ['reason' => 'complaint']],
            ['idempotency_key' => $idempotencyKey],
        )->id;
    }
}
