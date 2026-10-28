<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $user->username }},</p>
    <p>hai chiesto di reimpostare la password di TooGood Piante. Il codice da inserire nell'app è:</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>Vale 30 minuti. Se non l'hai chiesto tu, ignora questa email: la tua password non cambia.</p>
</body>
</html>
