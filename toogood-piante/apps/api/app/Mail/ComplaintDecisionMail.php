<?php

namespace App\Mail;

use App\Models\Complaint;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Esito del reclamo, al cliente (`$forSeller` false) e al venditore (true). */
class ComplaintDecisionMail extends Mailable
{
    public function __construct(public Complaint $complaint, public bool $forSeller) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Esito del reclamo sull'ordine {$this->complaint->order->number()}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.complaint-decision');
    }
}
