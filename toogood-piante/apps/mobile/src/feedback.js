// Recensioni e reclami (giorno 12): testi e controlli, senza React Native, quindi provati con `npm test`.
import { formatEuro } from './pricing.js';

export const RATING_LABELS = ['', 'Pessimo', 'Scarso', 'Discreto', 'Buono', 'Ottimo'];

// 4 → "★★★★☆"
export const stars = (n) => '★'.repeat(n) + '☆'.repeat(5 - n);

export const COMMENT_MAX = 1000;
export const COMPLAINT_MIN = 10;
export const COMPLAINT_MAX = 2000;

// Il voto è obbligatorio, il commento no. Restituisce il messaggio d'errore, o '' se va bene.
export function reviewError({ rating, comment = '' }) {
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) return 'Scegli un voto da 1 a 5 stelle.';
  if (comment.length > COMMENT_MAX) return `Il commento può avere al massimo ${COMMENT_MAX} caratteri.`;

  return '';
}

// Il motivo si sceglie tra quelli ammessi adesso (`allowed`, dal server); la descrizione è solo testo.
export function complaintError({ reason, description = '' }, allowed = []) {
  if (!reason || !allowed.includes(reason)) return 'Scegli il motivo del reclamo.';
  const length = description.trim().length;
  if (length < COMPLAINT_MIN) return `Racconta cosa è successo (almeno ${COMPLAINT_MIN} caratteri).`;
  if (length > COMPLAINT_MAX) return `La descrizione può avere al massimo ${COMPLAINT_MAX} caratteri.`;

  return '';
}

// Stato di un reclamo. `tone`: ok (verde), warn (arancio), bad (rosso), muted (grigio)
const COMPLAINT_STATUS = {
  OPEN: { label: 'In esame', tone: 'warn' },
  ACCEPTED_FULL: { label: 'Rimborso totale', tone: 'ok' },
  ACCEPTED_PARTIAL: { label: 'Rimborso parziale', tone: 'ok' },
  REJECTED: { label: 'Respinto', tone: 'bad' },
};

export const complaintStatusInfo = (status) => COMPLAINT_STATUS[status] ?? { label: status, tone: 'muted' };

// Cosa dice al cliente l'esito del suo reclamo
export function complaintOutcomeText(c) {
  switch (c.status) {
    case 'OPEN': return 'Stiamo esaminando il reclamo: ti avviseremo per email con l’esito.';
    case 'ACCEPTED_FULL': return `Reclamo accolto: ti rimborsiamo ${formatEuro(c.refund_cents)}, cioè tutto l’ordine. L’importo torna sulla tua carta in pochi giorni.`;
    case 'ACCEPTED_PARTIAL': return `Reclamo accolto in parte: ti rimborsiamo ${formatEuro(c.refund_cents)}. L’importo torna sulla tua carta in pochi giorni.`;
    case 'REJECTED': return 'Reclamo respinto: non è previsto alcun rimborso.';
    default: return '';
  }
}

// Cosa dice al venditore lo stato del reclamo su un suo ordine
export function sellerComplaintLine(c) {
  switch (c.status) {
    case 'OPEN': return 'In esame da parte della piattaforma: il pagamento di questo ordine è sospeso.';
    case 'ACCEPTED_FULL': return 'Rimborso totale al cliente: per questo ordine non è previsto alcun pagamento.';
    case 'ACCEPTED_PARTIAL': return `Rimborso parziale di ${formatEuro(c.refund_cents)}: il pagamento di questo ordine è ridotto dello stesso importo.`;
    case 'REJECTED': return 'Reclamo respinto: il pagamento di questo ordine prosegue.';
    default: return '';
  }
}

// "2026-06-16T16:00:00+00:00" → "16/06 alle 18:00", nel fuso del telefono
export function localDateTimeOf(iso) {
  const d = new Date(iso);
  const two = (n) => String(n).padStart(2, '0');

  return `${two(d.getDate())}/${two(d.getMonth() + 1)} alle ${two(d.getHours())}:${two(d.getMinutes())}`;
}

// Fino a quando si può aprire il reclamo (`options` è `complaint_options` dell'ordine)
export const complaintDeadlineText = (options) => (options?.until ? `Puoi aprire un reclamo fino al ${localDateTimeOf(options.until)}.` : '');

// 4.3 → "4,3 su 5 · 7 recensioni"
export function reviewSummaryText(summary) {
  if (!summary || !summary.count) return 'Nessuna recensione ancora.';

  return `${String(summary.average).replace('.', ',')} su 5 · ${summary.count} ${summary.count === 1 ? 'recensione' : 'recensioni'}`;
}
