<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCodeMail extends Mailable
{
    public function __construct(public User $user, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recupero della password di TooGood Piante');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
