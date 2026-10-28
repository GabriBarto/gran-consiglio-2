<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $user->username }},</p>
    <p>benvenuto su TooGood Piante! Per attivare l'account conferma il tuo indirizzo email:</p>
    <p><a href="{{ $url }}" style="background: #15803d; color: #ffffff; padding: 10px 18px; text-decoration: none; border-radius: 6px;">Conferma email</a></p>
    <p>Il link vale 24 ore e si può usare una sola volta. Se non ti sei registrato tu, ignora questa email.</p>
</body>
</html>
