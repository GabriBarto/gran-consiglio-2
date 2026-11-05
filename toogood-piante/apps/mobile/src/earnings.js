// Come mostrare gli incassi del venditore (US-V16). Senza React Native, quindi provate con `npm test`.
import { formatEuro } from './pricing.js';

// Stato del pagamento di un ordine al venditore. `tone`: ok (verde), warn (arancio), bad (rosso), muted (grigio)
const PAYOUT = {
  SENT: { label: 'Trasferito', tone: 'ok' },
  SCHEDULED: { label: 'In attesa', tone: 'warn' },
  HELD: { label: 'Sospeso', tone: 'bad' },
  SKIPPED: { label: 'Nessun trasferimento', tone: 'muted' },
  REVERSED: { label: 'Stornato', tone: 'bad' },
};

export const payoutInfo = (status) => PAYOUT[status] ?? { label: status, tone: 'muted' };

// "2026-06-16T19:00:00+02:00" → "16/06/2026"
const dayOf = (iso) => iso.slice(0, 10).split('-').reverse().join('/');

// La riga di un ordine: cosa succede al suo pagamento e quando
export function payoutLine(row) {
  const when = row.payout_status === 'SENT' && row.sent_at ? dayOf(row.sent_at) : dayOf(row.scheduled_at);

  switch (row.payout_status) {
    case 'SENT': return `Trasferiti ${formatEuro(row.payout_cents)} il ${when}.`;
    case 'SCHEDULED': return row.payout_cents > 0
      ? `${formatEuro(row.payout_cents)} verranno trasferiti dopo il ${when}.`
      : 'Il guadagno è zero o negativo: nessun trasferimento.';
    case 'HELD': return 'Sospeso fino alla decisione sul reclamo.';
    case 'SKIPPED': return 'Nessun trasferimento per questo ordine.';
    default: return '';
  }
}

// Guadagno di un ordine: se è negativo si dice chiaramente che non verrà addebitato nulla
export function earningText(row) {
  if (row.earning_cents >= 0) return `Guadagno ${formatEuro(row.earning_cents)}`;

  return `Guadagno ${formatEuro(row.earning_cents)} (nessun addebito)`;
}

// Le righe del riepilogo, nell'ordine in cui si mostrano
export function summaryRows(summary) {
  return [
    { key: 'transferred', label: 'Già trasferito', value: formatEuro(summary.transferred_cents), tone: 'ok' },
    { key: 'pending', label: 'In attesa di trasferimento', value: formatEuro(summary.pending_cents), tone: 'warn' },
    { key: 'held', label: 'Sospeso (reclami)', value: formatEuro(summary.held_cents), tone: summary.held_cents > 0 ? 'bad' : 'muted' },
    { key: 'fees', label: 'Tariffe trattenute', value: formatEuro(summary.fees_cents), tone: 'muted' },
  ];
}
