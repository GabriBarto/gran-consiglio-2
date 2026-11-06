<?php

namespace App\Services\Complaints;

use App\Mail\ComplaintDecisionMail;
use App\Mail\ComplaintOpenedMail;
use App\Models\Complaint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Email sui reclami. Partono a stato già salvato; un errore di invio non annulla né una decisione né un rimborso. */
class ComplaintNotifier
{
    /** Il venditore sa subito che un suo ordine è contestato (US-V18). */
    public function opened(Complaint $complaint): void
    {
        $complaint->load(['order.seller.user', 'order.customer']);
        $this->send(fn () => Mail::to($complaint->order->seller->user->email)->send(new ComplaintOpenedMail($complaint)), $complaint);
    }

    /** L'esito arriva a entrambi: rimborso totale, parziale o rifiuto (US-C14 CA3, US-V18). */
    public function decided(Complaint $complaint): void
    {
        $complaint->load(['order.seller.user', 'order.customer']);
        $this->send(fn () => Mail::to($complaint->order->customer->email)->send(new ComplaintDecisionMail($complaint, false)), $complaint);
        $this->send(fn () => Mail::to($complaint->order->seller->user->email)->send(new ComplaintDecisionMail($complaint, true)), $complaint);
    }

    private function send(callable $send, Complaint $complaint): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning("Email del reclamo {$complaint->id} non inviata: {$e->getMessage()}");
        }
    }
}
