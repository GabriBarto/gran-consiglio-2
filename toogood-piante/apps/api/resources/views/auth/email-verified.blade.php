<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TooGood Piante</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; text-align: center; padding: 48px 16px;">
    @if ($ok)
        <h1>Email confermata</h1>
        <p>Il tuo indirizzo è stato verificato. Torna nell'app TooGood Piante per accedere.</p>
    @else
        <h1>Link non valido</h1>
        <p>Il link è scaduto o non è corretto. Dall'app puoi richiedere una nuova email di conferma.</p>
    @endif
</body>
</html>
