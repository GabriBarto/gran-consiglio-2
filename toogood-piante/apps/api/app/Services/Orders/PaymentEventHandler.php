<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

/**
 * Elabora gli eventi di Stripe (già verificati dal webhook, o costruiti dal pagamento simulato).
 *
 * Idempotente: Stripe può inviare lo stesso evento più volte. L'id dell'evento è registrato in `stripe_events`
 * e un evento già elaborato viene ignorato; in più ogni passaggio dell'ordine controlla da sé lo stato
 * dentro una transazione, quindi anche due consegne simultanee non fanno danni.
 */
class PaymentEventHandler
{
    public function __construct(private readonly OrderLifecycle $lifecycle) {}

    /** @return string esito: paid, late_refund, released, ignored, unknown o duplicate */
    public function handle(array $event): string
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($id === '') {
            return 'ignored';
        }

        // Se l'evento esiste già, insertOrIgnore non inserisce nulla
        DB::table('stripe_events')->insertOrIgnore(['id' => $id, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);

        if (DB::table('stripe_events')->where('id', $id)->value('processed_at') !== null) {
            return 'duplicate';
        }

        $intentId = $event['data']['object']['id'] ?? null;

        $outcome = match (true) {
            $intentId === null => 'ignored',
            $type === 'payment_intent.succeeded' => $this->lifecycle->onPaymentSucceeded($intentId),
            $type === 'payment_intent.payment_failed' => $this->lifecycle->onPaymentFailed($intentId) ? 'released' : 'ignored',
            default => 'ignored',
        };

        // Segnato come elaborato solo a lavoro finito: se qualcosa fallisce Stripe riprova e l'evento viene rifatto
        DB::table('stripe_events')->where('id', $id)->update(['processed_at' => now()]);

        return $outcome;
    }
}
