<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyEmailMail extends Mailable
{
    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Conferma il tuo indirizzo email su TooGood Piante');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email');
    }
}
