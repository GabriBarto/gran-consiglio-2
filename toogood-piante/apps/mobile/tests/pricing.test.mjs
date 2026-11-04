import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { eurosToCents, formatDistance, formatEuro, quote } from '../src/pricing.js';

const { cases } = JSON.parse(readFileSync(new URL('../../../contracts/price-cases.json', import.meta.url), 'utf8'));

for (const c of cases) {
  test(`formula del prezzo: ${c.name}`, () => {
    const q = quote(c.list_value_cents, c.discount_pct, c.fee_cents);
    assert.equal(q.price_cents, c.price_cents);
    assert.equal(q.earning_cents, c.earning_cents);
  });
}

test('eurosToCents accetta virgola, punto e numeri interi', () => {
  assert.equal(eurosToCents('20'), 2000);
  assert.equal(eurosToCents('20,5'), 2050);
  assert.equal(eurosToCents('20.50'), 2050);
  assert.equal(eurosToCents(' 0,99 '), 99);
  assert.equal(eurosToCents('7,05'), 705);
});

test('eurosToCents rifiuta testo, negativi e troppi decimali', () => {
  for (const bad of ['', 'abc', '-5', '1,234', '1,2,3', '€5', '123456']) {
    assert.equal(eurosToCents(bad), null, bad);
  }
});

test('formatEuro mostra virgola e segno meno anche per i guadagni negativi', () => {
  assert.equal(formatEuro(700), '7,00 €');
  assert.equal(formatEuro(5), '0,05 €');
  assert.equal(formatEuro(-60), '−0,60 €');
  assert.equal(formatEuro(0), '0,00 €');
});

test('formatDistance: metri sotto il chilometro, km con virgola fino a 10, poi interi', () => {
  assert.equal(formatDistance(null), '');
  assert.equal(formatDistance(4), '10 m');
  assert.equal(formatDistance(347), '350 m');
  assert.equal(formatDistance(999), '1000 m');
  assert.equal(formatDistance(1000), '1,0 km');
  assert.equal(formatDistance(1234), '1,2 km');
  assert.equal(formatDistance(9949), '9,9 km');
  assert.equal(formatDistance(14400), '14 km');
  assert.equal(formatDistance(141027), '141 km');
});
