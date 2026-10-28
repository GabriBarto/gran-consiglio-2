<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginCodeMail extends Mailable
{
    public function __construct(public User $user, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Il tuo codice di accesso a TooGood Piante');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.login-code');
    }
}
