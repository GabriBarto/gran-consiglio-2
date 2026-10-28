<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $user->username }},</p>
    <p>questo è il tuo codice per accedere a TooGood Piante:</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>Vale {{ \App\Services\Auth\TwoFactorService::TTL_MINUTES }} minuti e si può usare una sola volta.
       Se non hai provato ad accedere tu, ignora questa email e cambia la password.</p>
</body>
</html>
