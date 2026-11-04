<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avviso di nuovo ordine al venditore, per prepararlo (US-V12). */
class NewOrderMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nuovo ordine {$this->order->number()} su TooGood Piante");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-order');
    }
}
