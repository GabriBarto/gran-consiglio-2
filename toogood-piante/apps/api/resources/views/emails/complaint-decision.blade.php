<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    @if ($forSeller)
        <p>Ciao {{ $complaint->order->seller->business_name }},</p>
        <p>la piattaforma ha deciso il reclamo sull'ordine <strong>{{ $complaint->order->number() }}</strong>.</p>
    @else
        <p>Ciao {{ $complaint->order->customer->username }},</p>
        <p>abbiamo esaminato il tuo reclamo sull'ordine <strong>{{ $complaint->order->number() }}</strong> presso
           {{ $complaint->order->seller->business_name }}.</p>
    @endif

    @if ($complaint->status === \App\Models\Complaint::ACCEPTED_FULL)
        <p><strong>Esito: reclamo accolto, rimborso totale</strong> di
           {{ number_format($complaint->refund_cents / 100, 2, ',', '.') }} €.</p>
        @if ($forSeller)
            <p>Per questo ordine non è previsto alcun pagamento (o, se era già partito, viene ripreso).</p>
        @else
            <p>L'importo tornerà sulla tua carta in pochi giorni.</p>
        @endif
    @elseif ($complaint->status === \App\Models\Complaint::ACCEPTED_PARTIAL)
        <p><strong>Esito: reclamo accolto, rimborso parziale</strong> di
           {{ number_format($complaint->refund_cents / 100, 2, ',', '.') }} €.</p>
        @if ($forSeller)
            <p>Il pagamento di questo ordine è ridotto dello stesso importo.</p>
        @else
            <p>L'importo tornerà sulla tua carta in pochi giorni.</p>
        @endif
    @else
        <p><strong>Esito: reclamo respinto.</strong> Non è previsto alcun rimborso.</p>
        @if ($forSeller)
            <p>Il pagamento di questo ordine riprende il suo corso.</p>
        @endif
    @endif

    <p><strong>Motivazione:</strong> {{ $complaint->decision_note }}</p>
</body>
</html>
