<?php

namespace App\Services\Seller;

use App\Mail\SellerSuspensionMail;
use App\Models\Complaint;
use App\Models\Seller;
use App\Services\Boxes\BusinessConfig;
use App\Support\ApiError;
use App\Support\Clock;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sospensione e riattivazione dei venditori (US-S03, US-V18 CA1).
 *
 * Da sospeso un negozio sparisce dalla ricerca, non può pubblicare box né ricevere nuovi ordini (`canPublish` è falso
 * se lo stato non è APPROVED). Gli ordini già pagati si ritirano comunque e il loro payout segue la regola di sempre:
 * la sospensione non tocca né la scansione né i pagamenti. Il motivo sta in `verification_note`.
 *
 * La sospensione automatica scatta quando i reclami accolti (totale o parziale) degli ultimi N giorni raggiungono la
 * soglia: parametri `suspend_after_complaints` e `suspend_window_days`. Si riattiva a mano dall'admin.
 */
class SellerSuspension
{
    public function __construct(private readonly Clock $clock, private readonly BusinessConfig $config) {}

    /** Sospensione decisa dall'admin: solo un venditore approvato si può sospendere. */
    public function suspend(Seller $seller, string $reason): Seller
    {
        DB::transaction(function () use ($seller, $reason) {
            $locked = Seller::lockForUpdate()->findOrFail($seller->id);
            if ($locked->verification_status !== Seller::STATUS_APPROVED) {
                ApiError::throw('INVALID_STATE', 'Si può sospendere solo un venditore approvato.', 409);
            }
            $locked->forceFill(['verification_status' => Seller::STATUS_SUSPENDED, 'verification_note' => $reason])->save();
        });

        $seller->refresh();
        $this->notify($seller, true, $reason);

        return $seller;
    }

    public function reactivate(Seller $seller): Seller
    {
        DB::transaction(function () use ($seller) {
            $locked = Seller::lockForUpdate()->findOrFail($seller->id);
            if ($locked->verification_status !== Seller::STATUS_SUSPENDED) {
                ApiError::throw('INVALID_STATE', 'Il venditore non è sospeso.', 409);
            }
            $locked->forceFill(['verification_status' => Seller::STATUS_APPROVED, 'verification_note' => null])->save();
        });

        $seller->refresh();
        $this->notify($seller, false);

        return $seller;
    }

    /** Dopo l'accoglimento di un reclamo: sospende il venditore se ne ha accolti troppi di recente. */
    public function suspendIfRepeatedComplaints(Seller $seller): bool
    {
        $threshold = $this->config->intOr('suspend_after_complaints', 3);
        if ($threshold < 1 || $seller->verification_status !== Seller::STATUS_APPROVED) {
            return false;
        }
        $days = $this->config->intOr('suspend_window_days', 30);

        $accepted = Complaint::query()
            ->whereIn('status', [Complaint::ACCEPTED_FULL, Complaint::ACCEPTED_PARTIAL])
            ->where('decided_at', '>=', $this->clock->now()->subDays($days))
            ->whereHas('order', fn ($q) => $q->where('seller_id', $seller->id))
            ->count();

        if ($accepted < $threshold) {
            return false;
        }

        try {
            $this->suspend($seller, "Sospeso automaticamente: {$accepted} reclami accolti negli ultimi {$days} giorni.");
        } catch (HttpResponseException) {
            return false; // sospeso nel frattempo da qualcun altro
        }

        return true;
    }

    private function notify(Seller $seller, bool $suspended, ?string $reason = null): void
    {
        try {
            Mail::to($seller->user->email)->send(new SellerSuspensionMail($seller, $suspended, $reason));
        } catch (Throwable $e) {
            Log::warning("Email di sospensione del venditore {$seller->id} non inviata: {$e->getMessage()}");
        }
    }
}
