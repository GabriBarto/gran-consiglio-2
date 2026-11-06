<?php

namespace App\Mail;

use App\Models\Complaint;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Al venditore: un cliente ha aperto un reclamo su un suo ordine. */
class ComplaintOpenedMail extends Mailable
{
    public function __construct(public Complaint $complaint) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reclamo sull'ordine {$this->complaint->order->number()}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.complaint-opened');
    }
}
