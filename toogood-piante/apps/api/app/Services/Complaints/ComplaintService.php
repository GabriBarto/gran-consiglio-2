<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\User;
use App\Services\Seller\SellerSuspension;
use App\Services\Boxes\BusinessConfig;
use App\Services\Stripe\PaymentService;
use App\Services\Stripe\TransferService;
use App\Support\ApiError;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reclami e rimborsi (US-C14, US-S05, RB-10).
 *
 * Apertura: solo per i quattro casi ammessi e solo entro il termine (`complaint_window_hours`, 24 ore): dal ritiro
 * per i casi b–d, dalla fine della fascia per il caso a (negozio chiuso o box non consegnata). Il termine lo
 * controlla sempre il server. Un reclamo aperto porta l'ordine a IN_COMPLAINT e sospende il payout.
 *
 * Decisione dell'admin: rimborso totale, parziale o rifiuto. Il rimborso parte su Stripe con una chiave di
 * idempotenza legata al reclamo; il guadagno del venditore si riduce dello stesso importo (mai sotto zero) e, se era
 * già stato trasferito, si riprende con uno storno. Ogni passaggio blocca le righe coinvolte, così due decisioni
 * contemporanee non rimborsano due volte.
 */
class ComplaintService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessConfig $config,
        private readonly PaymentService $payments,
        private readonly TransferService $transfers,
        private readonly ComplaintNotifier $notifier,
        private readonly SellerSuspension $suspension,
    ) {}

    /**
     * Quando e per quali motivi l'ordine si può contestare. Null se il suo stato non lo permette
     * (mai pagato, già rimborsato, reclamo già presente…).
     *
     * @return array{reasons: list<string>, from: CarbonImmutable, until: CarbonImmutable}|null
     */
    public function window(Order $order): ?array
    {
        if ($order->complaint) {
            return null;
        }
        $hours = $this->config->int('complaint_window_hours');

        // Ritirato: contenuto non conforme. I casi b–d decorrono dal ritiro.
        if ($order->status === Order::PICKED_UP && $order->picked_up_at) {
            return [
                'reasons' => [Complaint::VALUE_BELOW_DECLARED, Complaint::PLANTS_DAMAGED, Complaint::NOT_AS_DECLARED],
                'from' => CarbonImmutable::instance($order->picked_up_at),
                'until' => CarbonImmutable::instance($order->picked_up_at)->addHours($hours),
            ];
        }

        // Non ritirato: l'unico caso è il negozio chiuso o la box non consegnata, dal momento in cui il ritiro è aperto
        if (in_array($order->status, [Order::PAID, Order::NOT_PICKED_UP], true)) {
            $from = $order->pickupToken?->valid_from;

            return [
                'reasons' => [Complaint::CLOSED_OR_NOT_DELIVERED],
                'from' => $from ? CarbonImmutable::instance($from) : CarbonImmutable::instance($order->pickup_window_end_at),
                'until' => CarbonImmutable::instance($order->pickup_window_end_at)->addHours($hours),
            ];
        }

        return null;
    }

    /** Come `window`, ma solo se adesso si può davvero aprire il reclamo (altrimenti l'elenco dei motivi è vuoto). */
    public function openNow(Order $order): array
    {
        $w = $this->window($order);
        $now = $this->clock->now();
        if (! $w || $now->lt($w['from']) || $now->gt($w['until'])) {
            return ['reasons' => [], 'until' => $w['until'] ?? null];
        }

        return ['reasons' => $w['reasons'], 'until' => $w['until']];
    }

    public function open(User $customer, int $orderId, string $reason, string $description): Complaint
    {
        $complaint = DB::transaction(function () use ($customer, $orderId, $reason, $description) {
            // Riga bloccata: due reclami insieme sullo stesso ordine non passano entrambi
            $order = Order::where('customer_id', $customer->id)->lockForUpdate()->find($orderId)
                ?? ApiError::throw('NOT_FOUND', 'Ordine non trovato.', 404);

            if ($order->complaint) {
                ApiError::throw('COMPLAINT_EXISTS', 'Hai già aperto un reclamo su questo ordine.', 409);
            }
            $w = $this->window($order);
            if (! $w) {
                ApiError::throw('COMPLAINT_NOT_ALLOWED', 'Su questo ordine non si può aprire un reclamo.', 409);
            }
            $now = $this->clock->now();
            if ($now->lt($w['from'])) {
                ApiError::throw('COMPLAINT_NOT_ALLOWED', 'Il ritiro non è ancora iniziato: non puoi ancora aprire un reclamo.', 409);
            }
            if ($now->gt($w['until'])) {
                ApiError::throw('COMPLAINT_WINDOW_CLOSED', 'Il termine per aprire un reclamo su questo ordine è scaduto.', 409);
            }
            if (! in_array($reason, $w['reasons'], true)) {
                ApiError::throw('REASON_NOT_ALLOWED', 'Questo motivo non è ammesso per questo ordine.', 422);
            }

            $complaint = new Complaint;
            $complaint->order_id = $order->id;
            $complaint->customer_id = $customer->id;
            $complaint->reason = $reason;
            $complaint->description = $description;
            $complaint->status = Complaint::OPEN;
            $complaint->refund_cents = 0;
            $complaint->save();

            $order->status = Order::IN_COMPLAINT;
            $order->save();

            // Il pagamento al venditore aspetta la decisione (RB-11)
            Payout::where('order_id', $order->id)->where('status', Payout::SCHEDULED)->update(['status' => Payout::HELD]);

            return $complaint;
        });

        $this->notifier->opened($complaint);

        return $complaint;
    }

    /**
     * @param  'ACCEPT_FULL'|'ACCEPT_PARTIAL'|'REJECT'  $decision
     */
    public function decide(User $admin, int $complaintId, string $decision, ?int $refundCents, string $note, bool $suspendSeller = false): Complaint
    {
        $complaint = DB::transaction(function () use ($admin, $complaintId, $decision, $refundCents, $note) {
            $complaint = Complaint::lockForUpdate()->find($complaintId)
                ?? ApiError::throw('NOT_FOUND', 'Reclamo non trovato.', 404);
            if ($complaint->status !== Complaint::OPEN) {
                ApiError::throw('INVALID_STATE', 'Questo reclamo è già stato deciso.', 409);
            }
            $order = Order::lockForUpdate()->findOrFail($complaint->order_id);
            $payout = Payout::where('order_id', $order->id)->lockForUpdate()->first();

            if ($decision === 'REJECT') {
                $complaint->status = Complaint::REJECTED;
                $order->status = Order::COMPLAINT_REJECTED;
                // L'attesa è finita senza sorprese: il pagamento al venditore riprende il suo corso
                if ($payout && $payout->status === Payout::HELD) {
                    $payout->status = Payout::SCHEDULED;
                    $payout->save();
                }
            } else {
                $full = $decision === 'ACCEPT_FULL';
                $amount = $full ? $order->total_cents : (int) $refundCents;
                if (! $full && ($amount < 1 || $amount >= $order->total_cents)) {
                    ApiError::throw('INVALID_REFUND_AMOUNT', 'Il rimborso parziale deve essere maggiore di zero e minore del totale dell\'ordine.', 422);
                }
                if (! $order->stripe_payment_intent_id) {
                    ApiError::throw('INVALID_STATE', 'L\'ordine non ha un pagamento da rimborsare.', 409);
                }

                // Rimborso e storno su Stripe dentro la transazione: se uno fallisce non cambia nulla e la decisione
                // si può ripetere (le chiavi di idempotenza evitano di rimborsare due volte).
                $refundId = $this->stripe(fn () => $this->payments->refundAmount($order->stripe_payment_intent_id, $amount, "complaint-{$complaint->id}-refund"));

                $refund = new Refund;
                $refund->order_id = $order->id;
                $refund->complaint_id = $complaint->id;
                $refund->amount_cents = $amount;
                $refund->stripe_refund_id = $refundId;
                $refund->save();

                if ($payout) {
                    $this->adjustPayout($payout, $complaint, $full, $amount);
                }

                $complaint->status = $full ? Complaint::ACCEPTED_FULL : Complaint::ACCEPTED_PARTIAL;
                $complaint->refund_cents = $amount;
                $order->status = $full ? Order::REFUNDED_FULL : Order::REFUNDED_PARTIAL;
            }

            $complaint->decision_note = $note;
            $complaint->decided_by = $admin->id;
            $complaint->decided_at = $this->clock->now();
            $complaint->save();
            $order->save();

            return $complaint;
        });

        $this->notifier->decided($complaint);
        $this->suspendAfterDecision($complaint, $suspendSeller);

        return $complaint;
    }

    /**
     * Ammonizione o sospensione (US-S05 CA2): l'admin può sospendere subito il venditore; in più, dopo troppi reclami
     * accolti di recente, la sospensione scatta da sola (US-V18 CA1). Avviene a decisione già salvata.
     */
    private function suspendAfterDecision(Complaint $complaint, bool $requested): void
    {
        $order = Order::with('seller.user')->findOrFail($complaint->order_id);
        $seller = $order->seller;
        if ($seller->verification_status !== Seller::STATUS_APPROVED) {
            return;
        }
        if ($requested) {
            $this->suspension->suspend($seller, "Sospeso dopo il reclamo sull'ordine {$order->number()}: {$complaint->decision_note}");

            return;
        }
        if (in_array($complaint->status, [Complaint::ACCEPTED_FULL, Complaint::ACCEPTED_PARTIAL], true)) {
            $this->suspension->suspendIfRepeatedComplaints($seller);
        }
    }

    /**
     * Il guadagno del venditore segue il rimborso: totale → niente; parziale → ridotto dello stesso importo, mai
     * sotto zero (la parte che eccede la assorbe la piattaforma). Se il trasferimento era già partito, si riprende.
     */
    private function adjustPayout(Payout $payout, Complaint $complaint, bool $full, int $refundCents): void
    {
        $reduction = $full ? $payout->amount_cents : min($refundCents, $payout->amount_cents);

        if (in_array($payout->status, [Payout::SCHEDULED, Payout::HELD], true)) {
            if ($full) {
                $payout->status = Payout::SKIPPED;
            } else {
                $payout->amount_cents -= $reduction;
                // Torna in programma: se l'importo è sceso a zero, il comando lo salterà
                $payout->status = Payout::SCHEDULED;
            }
            $payout->save();

            return;
        }

        if ($payout->status === Payout::SENT && $reduction > 0) {
            $this->stripe(fn () => $this->transfers->reverse($payout, $reduction, "complaint-{$complaint->id}-reversal"));
            if ($full || $reduction >= $payout->amount_cents) {
                $payout->status = Payout::REVERSED;
            } else {
                // Resta il netto che il venditore ha davvero incassato
                $payout->amount_cents -= $reduction;
            }
            $payout->save();
        }
    }

    /** Esegue una chiamata a Stripe; se non riesce, nulla viene salvato e l'admin può riprovare. */
    private function stripe(callable $call): mixed
    {
        try {
            return $call();
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning("Stripe non ha eseguito il rimborso del reclamo: {$e->getMessage()}");
            ApiError::throw('PAYMENT_PROVIDER_ERROR', 'Il servizio di pagamento non ha risposto: la decisione non è stata salvata, riprova tra poco.', 502);
        }
    }
}
