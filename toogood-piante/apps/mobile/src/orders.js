// Piccole funzioni per mostrare gli ordini. Senza React Native, quindi provate con `npm test`.

// "2026-06-15T17:00:00+02:00" → "17:00": l'ora è già quella di Roma, senza passare dal fuso del telefono
export const timeOf = (iso) => iso.slice(11, 16);

// "2026-06-15" → "15/06/2026"
export const formatDate = (isoDate) => isoDate.slice(0, 10).split('-').reverse().join('/');

// L'ora di un istante qualsiasi (es. fino a quando le box sono tue), nel fuso del telefono: "08:10"
export function localTimeOf(iso) {
  const d = new Date(iso);

  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

// Tempo che resta, come "09:41". Mai negativo.
export function formatCountdown(ms) {
  const total = Math.max(0, Math.ceil(ms / 1000));

  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

// Come mostrare lo stato di un ordine. `tone`: ok (verde), warn (arancio), bad (rosso), muted (grigio)
const STATUS = {
  PENDING_PAYMENT: { label: 'In attesa di pagamento', tone: 'warn' },
  PAID: { label: 'Pagato', tone: 'ok' },
  PICKED_UP: { label: 'Ritirato', tone: 'ok' },
  NOT_PICKED_UP: { label: 'Non ritirato', tone: 'bad' },
  IN_COMPLAINT: { label: 'In reclamo', tone: 'warn' },
  REFUNDED_FULL: { label: 'Rimborsato', tone: 'muted' },
  REFUNDED_PARTIAL: { label: 'Rimborsato in parte', tone: 'muted' },
  COMPLAINT_REJECTED: { label: 'Reclamo respinto', tone: 'muted' },
  EXPIRED: { label: 'Scaduto', tone: 'muted' },
  PAYMENT_FAILED: { label: 'Pagamento non riuscito', tone: 'bad' },
};

export const statusInfo = (status) => STATUS[status] ?? { label: status, tone: 'muted' };

/**
 * Le box che mancano, dalla risposta 409 OUT_OF_STOCK del server (`items: [{ box_id, requested, available }]`),
 * scritte con il nome che il cliente vede nel carrello.
 */
export function shortageLines(err, cart) {
  const items = err?.data?.items ?? [];

  return items.map(({ box_id: boxId, requested, available }) => {
    const title = cart.items.find((i) => i.box_id === boxId)?.title ?? 'Una box';

    return available === 0
      ? `«${title}»: è esaurita.`
      : `«${title}»: ne volevi ${requested}, ne restano ${available}.`;
  });
}

// Come finisce un pagamento che non ha portato a PAID. Restituisce il testo da mostrare.
export function paymentOutcomeText(status) {
  if (status === 'PAYMENT_FAILED') return 'Il pagamento non è riuscito. Le box sono di nuovo disponibili: puoi riprovare dal carrello.';
  if (status === 'REFUNDED_FULL') return 'Il pagamento è arrivato dopo la scadenza dei 10 minuti ed è stato rimborsato per intero. Se le box ci sono ancora puoi riprovare.';
  if (status === 'EXPIRED') return 'Il tempo per pagare è scaduto. Torna al carrello e riprova.';

  return 'Non è stato possibile completare il pagamento. Riprova dal carrello.';
}
