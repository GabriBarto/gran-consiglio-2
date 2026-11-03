<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TooGood Piante</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; text-align: center; padding: 48px 16px;">
    @if ($refresh)
        <h1>Collegamento da rifare</h1>
        <p>Il link è scaduto. Torna nell'app TooGood Piante e premi di nuovo "Collega il conto di pagamento".</p>
    @else
        <h1>Operazione terminata</h1>
        <p>Puoi chiudere questa pagina e tornare nell'app TooGood Piante: lo stato del conto si aggiorna da solo.</p>
    @endif
</body>
</html>
