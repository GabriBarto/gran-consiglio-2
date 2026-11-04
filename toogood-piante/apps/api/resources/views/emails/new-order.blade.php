<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $order->seller->user->username }},</p>
    <p>hai un nuovo ordine pagato: <strong>{{ $order->number() }}</strong>.</p>

    <ul>
        @foreach ($order->items as $item)
            <li>{{ $item->quantity }} × {{ $item->box->description ?: 'Box a sorpresa' }}</li>
        @endforeach
    </ul>

    <p>
        Il cliente <strong>{{ $order->customer->username }}</strong> ritira <strong>oggi</strong>
        dalle {{ substr($order->seller->pickup_from, 0, 5) }} alle {{ substr($order->seller->pickup_to, 0, 5) }}
        mostrando il codice QR dell'ordine.
    </p>
    <p>Prepara le box in tempo: gli ordini non si possono annullare.</p>
</body>
</html>
