// Piccole funzioni per mostrare gli ordini. Senza React Native, quindi provate con `npm test`.
import { errorMessage } from './errors.js';

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

/**
 * Stato del biglietto di ritiro adesso, calcolato sul telefono con i dati salvati: così funziona anche senza rete.
 * USED (già ritirato), NOT_YET (il ritiro non è iniziato), ACTIVE (si può mostrare al negozio), EXPIRED (fascia finita).
 */
export function ticketState(ticket, nowMs = Date.now()) {
  if (ticket.used_at) return 'USED';
  if (nowMs < Date.parse(ticket.valid_from)) return 'NOT_YET';
  if (nowMs > Date.parse(ticket.valid_until)) return 'EXPIRED';

  return 'ACTIVE';
}

// Cosa dire al cliente sotto il QR. `tone` come in statusInfo.
export function ticketMessage(ticket, nowMs = Date.now()) {
  const state = ticketState(ticket, nowMs);

  if (state === 'USED') return { state, tone: 'ok', text: `Ritirato alle ${localTimeOf(ticket.used_at)}. Questo codice non si può più usare.` };
  if (state === 'NOT_YET') return { state, tone: 'warn', text: `Il ritiro inizia alle ${timeOf(ticket.valid_from)} (fino alle ${timeOf(ticket.valid_until)}). Mostra il QR al negozio in quella fascia.` };
  if (state === 'EXPIRED') return { state, tone: 'bad', text: 'La fascia di ritiro è finita: questo codice non vale più.' };

  return { state, tone: 'ok', text: `Mostra questo QR al negozio entro le ${timeOf(ticket.valid_until)}.` };
}

// Perché una scansione non è andata a buon fine, con l'orario quando serve. `err` è l'errore dell'API.
export function scanErrorText(err) {
  if (err?.code === 'ALREADY_USED' && err.data?.used_at) {
    return `Questo codice è già stato usato alle ${localTimeOf(err.data.used_at)}: l’ordine è già stato ritirato.`;
  }
  if (err?.code === 'NOT_YET_VALID' && err.data?.valid_from) {
    return `Il ritiro non è ancora iniziato: inizia alle ${timeOf(err.data.valid_from)}.`;
  }

  return errorMessage(err);
}
