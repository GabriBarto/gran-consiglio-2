<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $seller->business_name }},</p>
    @if ($suspended)
        <p>il tuo negozio è stato <strong>sospeso</strong>: finché non viene riattivato non puoi pubblicare box e i clienti non possono fare nuovi ordini.</p>
        <p><strong>Motivo:</strong> {{ $reason }}</p>
        <p>Gli ordini che i clienti hanno già pagato si ritirano come sempre, e i relativi pagamenti seguono le regole di sempre.</p>
    @else
        <p>il tuo negozio è stato <strong>riattivato</strong>: puoi di nuovo pubblicare box e ricevere ordini.</p>
    @endif
</body>
</html>
