<?php

namespace App\Mail;

use App\Models\Seller;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Esito della verifica dell'attività, inviato al venditore dopo la decisione dell'admin. */
class SellerVerificationMail extends Mailable
{
    public function __construct(public Seller $seller, public bool $approved, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved
                ? 'La tua attività è stata approvata su TooGood Piante'
                : 'La verifica della tua attività su TooGood Piante',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.seller-verification');
    }
}
