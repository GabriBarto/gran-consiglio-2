import assert from 'node:assert/strict';
import test from 'node:test';
import { ticketMessage, ticketState, scanErrorText } from '../src/orders.js';

// Ritiro dalle 17:00 alle 19:00 a Roma (15:00–17:00 UTC) il 15 giugno 2026
const ticket = (over = {}) => ({
  qr: 'TGQR1.x.y', code: 'ABCD-EFGH', used_at: null,
  valid_from: '2026-06-15T17:00:00+02:00', valid_until: '2026-06-15T19:00:00+02:00', ...over,
});
const at = (utc) => Date.parse(`${utc}Z`);

test('ticketState: prima, durante e dopo la fascia di ritiro', () => {
  assert.equal(ticketState(ticket(), at('2026-06-15T14:59:59')), 'NOT_YET');
  assert.equal(ticketState(ticket(), at('2026-06-15T15:00:00')), 'ACTIVE');   // il primo istante vale
  assert.equal(ticketState(ticket(), at('2026-06-15T16:30:00')), 'ACTIVE');
  assert.equal(ticketState(ticket(), at('2026-06-15T17:00:00')), 'ACTIVE');   // anche l'ultimo
  assert.equal(ticketState(ticket(), at('2026-06-15T17:00:01')), 'EXPIRED');
});

test('ticketState: un biglietto già usato resta USED in ogni momento', () => {
  const used = ticket({ used_at: '2026-06-15T16:00:00+00:00' });

  assert.equal(ticketState(used, at('2026-06-15T14:00:00')), 'USED');
  assert.equal(ticketState(used, at('2026-06-15T16:30:00')), 'USED');
  assert.equal(ticketState(used, at('2026-06-16T10:00:00')), 'USED');
});

test('ticketMessage dice cosa fare in ogni stato, con gli orari di Roma', () => {
  const notYet = ticketMessage(ticket(), at('2026-06-15T08:00:00'));
  assert.equal(notYet.state, 'NOT_YET');
  assert.equal(notYet.tone, 'warn');
  assert.match(notYet.text, /inizia alle 17:00 \(fino alle 19:00\)/);

  const active = ticketMessage(ticket(), at('2026-06-15T16:00:00'));
  assert.equal(active.tone, 'ok');
  assert.match(active.text, /Mostra questo QR al negozio entro le 19:00/);

  const expired = ticketMessage(ticket(), at('2026-06-15T18:00:00'));
  assert.equal(expired.tone, 'bad');
  assert.match(expired.text, /non vale più/);

  const used = ticketMessage(ticket({ used_at: '2026-06-15T16:00:00+00:00' }), at('2026-06-15T16:10:00'));
  assert.equal(used.state, 'USED');
  assert.match(used.text, /^Ritirato alle \d{2}:\d{2}\./);
});

test('scanErrorText spiega ogni esito, con gli orari quando ci sono', () => {
  assert.match(scanErrorText({ code: 'INVALID_QR' }), /non è un biglietto di TooGood Piante/);
  assert.match(scanErrorText({ code: 'INVALID_CODE' }), /non riconosciuto/);
  assert.match(scanErrorText({ code: 'WRONG_SHOP' }), /altro negozio/);
  assert.match(scanErrorText({ code: 'EXPIRED' }), /fascia di ritiro.*finita/);
  assert.match(scanErrorText({ code: 'ORDER_NOT_ACTIVE' }), /non è più ritirabile/);

  assert.match(scanErrorText({ code: 'ALREADY_USED', data: { used_at: '2026-06-15T16:00:00+00:00' } }), /già stato usato alle \d{2}:\d{2}/);
  assert.match(scanErrorText({ code: 'ALREADY_USED', data: {} }), /già stato usato: l’ordine è già stato ritirato/);

  assert.equal(
    scanErrorText({ code: 'NOT_YET_VALID', data: { valid_from: '2026-06-15T17:00:00+02:00' } }),
    'Il ritiro non è ancora iniziato: inizia alle 17:00.',
  );
  assert.match(scanErrorText({ code: 'NOT_YET_VALID' }), /non è ancora iniziato/);
});

test('scanErrorText per errori di rete o sconosciuti usa il messaggio generico', () => {
  assert.match(scanErrorText({ code: 'NETWORK_ERROR' }), /contattare il server/);
  assert.match(scanErrorText({ status: 500 }), /Qualcosa è andato storto/);
  assert.equal(scanErrorText(undefined), '');
});
