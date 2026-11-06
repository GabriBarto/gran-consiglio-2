<?php

namespace App\Mail;

use App\Models\Seller;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Al venditore: il suo negozio è stato sospeso o riattivato. */
class SellerSuspensionMail extends Mailable
{
    public function __construct(public Seller $seller, public bool $suspended, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->suspended
                ? 'Il tuo negozio su TooGood Piante è stato sospeso'
                : 'Il tuo negozio su TooGood Piante è di nuovo attivo',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.seller-suspension');
    }
}
