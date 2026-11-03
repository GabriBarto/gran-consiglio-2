<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $seller->business_name }},</p>
    @if ($approved)
        <p>la tua attività è stata <strong>approvata</strong>. Per pubblicare le box ti manca solo di collegare il conto di pagamento dall'app, se non l'hai già fatto.</p>
    @else
        <p>purtroppo non abbiamo potuto approvare la tua attività.</p>
        <p><strong>Motivo:</strong> {{ $note }}</p>
        <p>Puoi correggere i dati o ricaricare i documenti dall'app e inviare di nuovo la richiesta.</p>
    @endif
</body>
</html>
