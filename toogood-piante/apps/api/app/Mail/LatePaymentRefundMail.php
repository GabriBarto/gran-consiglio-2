<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Il pagamento è arrivato quando le box non erano più tenute da parte: rimborsato per intero. */
class LatePaymentRefundMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pagamento rimborsato per l'ordine {$this->order->number()}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.late-payment-refund');
    }
}
