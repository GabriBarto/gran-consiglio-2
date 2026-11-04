<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $order->customer->username }},</p>
    <p>il tuo ordine <strong>{{ $order->number() }}</strong> è confermato: grazie per aver salvato delle piante dallo spreco!</p>

    <p>
        <strong>{{ $order->seller->business_name }}</strong><br>
        {{ $order->seller->address }}<br>
        Ritiro <strong>oggi</strong> dalle {{ substr($order->seller->pickup_from, 0, 5) }} alle {{ substr($order->seller->pickup_to, 0, 5) }}
    </p>

    <ul>
        @foreach ($order->items as $item)
            <li>{{ $item->quantity }} × {{ $item->box->description ?: 'Box a sorpresa' }}
                — {{ number_format($item->unit_price_cents * $item->quantity / 100, 2, ',', '.') }} €</li>
        @endforeach
    </ul>
    <p>Totale pagato: <strong>{{ number_format($order->total_cents / 100, 2, ',', '.') }} €</strong></p>

    <p>Per ritirare l'ordine ti servirà il <strong>codice QR</strong>: lo troverai nell'app, tra i tuoi ordini, e lo mostrerai al negozio nella fascia di ritiro.</p>
    <p style="color: #b91c1c;"><strong>Promemoria:</strong> se non ritiri l'ordine nella fascia indicata non è previsto alcun rimborso.</p>
</body>
</html>
