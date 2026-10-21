import crypto from 'node:crypto';
import { HttpError } from './http.js';

/**
 * Pagamento simulato: accetta qualsiasi carta di 16 cifre tranne quelle di test che simulano un rifiuto.
 * Quando si passerà a Stripe basta sostituire questa funzione (stessa firma, restituisce un riferimento).
 */
export const DECLINED_TEST_CARD = '4000000000000002';

export async function charge({ amountCents, card }) {
  const number = String(card?.number ?? '').replace(/\s+/g, '');
  if (!/^\d{16}$/.test(number)) throw new HttpError(400, 'Numero di carta non valido');
  if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(String(card?.expiry ?? ''))) throw new HttpError(400, 'Scadenza non valida (MM/AA)');
  if (number === DECLINED_TEST_CARD) throw new HttpError(402, 'Pagamento rifiutato dalla banca');
  return { ref: `SIM-${crypto.randomBytes(6).toString('hex')}`, amountCents };
}
