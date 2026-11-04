<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $order->customer->username }},</p>
    <p>il pagamento dell'ordine <strong>{{ $order->number() }}</strong> presso {{ $order->seller->business_name }} è arrivato quando
       le box non erano più tenute da parte per te.</p>
    <p>Non ti abbiamo addebitato nulla: l'importo di <strong>{{ number_format($order->total_cents / 100, 2, ',', '.') }} €</strong>
       ti è stato <strong>rimborsato per intero</strong> e tornerà sulla tua carta in pochi giorni.</p>
    <p>Se le box sono ancora disponibili puoi riprovare dall'app, completando il pagamento entro qualche minuto.</p>
</body>
</html>
