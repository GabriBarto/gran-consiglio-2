import assert from 'node:assert/strict';
import test from 'node:test';
import { earningText, payoutInfo, payoutLine, refundLine, summaryRows } from '../src/earnings.js';

const row = (over = {}) => ({
  payout_status: 'SCHEDULED', payout_cents: 550, earning_cents: 550,
  scheduled_at: '2026-06-16T19:00:00+02:00', sent_at: null, ...over,
});

test('payoutInfo: un nome e un colore per ogni stato', () => {
  assert.deepEqual(payoutInfo('SENT'), { label: 'Trasferito', tone: 'ok' });
  assert.equal(payoutInfo('HELD').tone, 'bad');
  assert.equal(payoutInfo('QUALCOSA').label, 'QUALCOSA');
});

test('payoutLine: in attesa dice quanto e dopo quando', () => {
  assert.equal(payoutLine(row()), '5,50 € verranno trasferiti dopo il 16/06/2026.');
});

test('payoutLine: trasferito dice il giorno dell\'invio, non quello programmato', () => {
  const sent = row({ payout_status: 'SENT', sent_at: '2026-06-17T10:00:00+02:00' });

  assert.equal(payoutLine(sent), 'Trasferiti 5,50 € il 17/06/2026.');
});

test('payoutLine: sospeso e importo zero sono spiegati', () => {
  assert.match(payoutLine(row({ payout_status: 'HELD' })), /reclamo/);
  assert.match(payoutLine(row({ payout_cents: 0, earning_cents: -50 })), /nessun trasferimento/);
  assert.match(payoutLine(row({ payout_status: 'SKIPPED', payout_cents: 0 })), /Nessun trasferimento/);
});

test('earningText: il guadagno negativo si mostra com\'è e dice che non c\'è nessun addebito', () => {
  assert.equal(earningText(row()), 'Guadagno 5,50 €');
  assert.equal(earningText(row({ earning_cents: -50 })), 'Guadagno −0,50 € (nessun addebito)');
});

test('summaryRows: trasferito, in attesa, sospeso e tariffe', () => {
  const rows = summaryRows({ transferred_cents: 550, pending_cents: 1100, held_cents: 0, fees_cents: 450 });

  assert.deepEqual(rows.map((r) => r.value), ['5,50 €', '11,00 €', '0,00 €', '4,50 €']);
  assert.equal(rows[2].tone, 'muted');
  assert.equal(summaryRows({ transferred_cents: 0, pending_cents: 0, held_cents: 300, fees_cents: 0 })[2].tone, 'bad');
});

test('refundLine: compare solo se c\'è stato un rimborso', () => {
  assert.equal(refundLine(row()), '');
  assert.equal(refundLine(row({ refunded_cents: 0 })), '');
  assert.equal(refundLine(row({ refunded_cents: 200 })), 'Rimborsati 2,00 € al cliente dopo un reclamo.');
});
