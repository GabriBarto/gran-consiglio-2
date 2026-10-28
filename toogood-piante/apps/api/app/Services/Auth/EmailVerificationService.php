<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

final class EmailVerificationService
{
    public const LINK_HOURS = 24;

    public function send(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'auth.verify-email',
            now()->addHours(self::LINK_HOURS),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        Mail::to($user->email)->send(new VerifyEmailMail($user, $url));
    }

    public function matches(User $user, string $hash): bool
    {
        return hash_equals(sha1($user->email), $hash);
    }
}
