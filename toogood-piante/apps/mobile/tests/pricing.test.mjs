import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { eurosToCents, formatEuro, quote } from '../src/pricing.js';

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
