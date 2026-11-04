<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Conferma dell'ordine al cliente dopo il pagamento (US-C08). */
class OrderConfirmationMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ordine {$this->order->number()} confermato su TooGood Piante");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-confirmation');
    }
}
