<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Ciao {{ $complaint->order->seller->business_name }},</p>
    <p>un cliente ({{ $complaint->order->customer->username }}) ha aperto un reclamo sull'ordine
       <strong>{{ $complaint->order->number() }}</strong>.</p>
    <p><strong>Motivo:</strong> {{ \App\Http\Resources\ComplaintResource::reasonLabel($complaint->reason) }}</p>
    <p><strong>Descrizione:</strong> {{ $complaint->description }}</p>
    <p>La decisione spetta alla piattaforma. Il pagamento di questo ordine resta <strong>sospeso</strong> fino all'esito,
       che ti comunicheremo per email.</p>
</body>
</html>
