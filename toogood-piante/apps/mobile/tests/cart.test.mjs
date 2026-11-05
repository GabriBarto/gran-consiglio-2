import assert from 'node:assert/strict';
import test from 'node:test';
import {
  addToCart, EMPTY_CART, isEmpty, isOtherShop, quantityInCart, removeItem, setQuantity, startCart, syncWithShop, toOrderPayload, totals,
} from '../src/cart.js';
import { formatCountdown, formatDate, localTimeOf, paymentOutcomeText, shortageLines, statusInfo, timeOf } from '../src/orders.js';

const shopA = { id: 1, business_name: 'Vivaio A', address: 'Via A 1', pickup_from: '17:00', pickup_to: '19:00', order_deadline: '2026-06-15T17:00:00+02:00' };
const shopB = { id: 2, business_name: 'Vivaio B', address: 'Via B 2', pickup_from: '16:00', pickup_to: '18:00', order_deadline: '2026-06-15T16:00:00+02:00' };
const box = (id, over = {}) => ({
  id, description: `Box ${id}`, box_type: { label: 'Box media' }, category: { label: 'Piante grasse' },
  price_cents: 700, list_value_cents: 2000, stock_available: 5, sold_out: false, orderable: true, ...over,
});

// ---------- carrello ----------

test('un carrello nuovo è vuoto', () => {
  assert.equal(isEmpty(EMPTY_CART), true);
  assert.deepEqual(totals(EMPTY_CART), { units: 0, total_cents: 0, min_saving_cents: 0 });
});

test('si aggiunge una box e il carrello ricorda il negozio', () => {
  const { cart, added, atLimit } = addToCart(EMPTY_CART, shopA, box(10), 2);

  assert.equal(added, 2);
  assert.equal(atLimit, false);
  assert.equal(cart.shop.id, 1);
  assert.equal(cart.shop.order_deadline, '2026-06-15T17:00:00+02:00');
  assert.equal(cart.items.length, 1);
  assert.equal(quantityInCart(cart, 10), 2);
});

test('aggiungere ancora la stessa box somma le quantità', () => {
  const first = addToCart(EMPTY_CART, shopA, box(10), 2).cart;
  const { cart } = addToCart(first, shopA, box(10), 1);

  assert.equal(cart.items.length, 1);
  assert.equal(quantityInCart(cart, 10), 3);
});

test('la quantità non supera mai lo stock residuo (US-C04 CA2)', () => {
  const full = box(10, { stock_available: 3 });
  const first = addToCart(EMPTY_CART, shopA, full, 2).cart;

  const second = addToCart(first, shopA, full, 5);
  assert.equal(second.added, 1);            // ne mancava una sola per arrivare a 3
  assert.equal(second.atLimit, true);
  assert.equal(quantityInCart(second.cart, 10), 3);

  const third = addToCart(second.cart, shopA, full, 1);
  assert.equal(third.added, 0);             // già al massimo
  assert.equal(third.atLimit, true);
  assert.equal(quantityInCart(third.cart, 10), 3);
});

test('con una sola box in stock non se ne aggiungono due', () => {
  const { cart, added } = addToCart(EMPTY_CART, shopA, box(10, { stock_available: 1 }), 4);

  assert.equal(added, 1);
  assert.equal(quantityInCart(cart, 10), 1);
});

test('il carrello è di un solo negozio (US-C04 CA1)', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10)).cart;

  assert.equal(isOtherShop(cart, 2), true);
  assert.equal(isOtherShop(cart, 1), false);
  assert.equal(isOtherShop(EMPTY_CART, 2), false);
  assert.throws(() => addToCart(cart, shopB, box(20)), /altro negozio/);

  // Dopo la conferma si ricomincia dal nuovo negozio
  const fresh = startCart(shopB, box(20), 2).cart;
  assert.equal(fresh.shop.id, 2);
  assert.deepEqual(fresh.items.map((i) => i.box_id), [20]);
});

test('setQuantity rispetta lo stock e zero toglie la riga', () => {
  let cart = addToCart(EMPTY_CART, shopA, box(10, { stock_available: 4 }), 2).cart;

  cart = setQuantity(cart, 10, 4);
  assert.equal(quantityInCart(cart, 10), 4);
  cart = setQuantity(cart, 10, 9);
  assert.equal(quantityInCart(cart, 10), 4);       // oltre lo stock: si ferma
  cart = setQuantity(cart, 10, 2.7);
  assert.equal(quantityInCart(cart, 10), 2);       // niente mezze box
  assert.equal(isEmpty(setQuantity(cart, 10, 0)), true);
  assert.equal(isEmpty(setQuantity(cart, 10, -1)), true);
});

test('togliendo l\'ultima riga il carrello dimentica il negozio', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10)).cart;
  const empty = removeItem(cart, 10);

  assert.equal(isEmpty(empty), true);
  assert.equal(empty.shop, null);
  assert.equal(isOtherShop(empty, 2), false); // ora si può aggiungere da un altro negozio
});

test('i totali sommano prezzo e risparmio minimo (US-C04 CA3)', () => {
  let cart = addToCart(EMPTY_CART, shopA, box(10, { price_cents: 700, list_value_cents: 2000 }), 2).cart;
  cart = addToCart(cart, shopA, box(11, { price_cents: 400, list_value_cents: 1000 }), 1).cart;

  assert.deepEqual(totals(cart), { units: 3, total_cents: 1800, min_saving_cents: 2 * 1300 + 600 });
});

test('il risparmio non è mai negativo, anche con listino sotto il prezzo', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10, { price_cents: 500, list_value_cents: 300 }), 2).cart;

  assert.equal(totals(cart).min_saving_cents, 0);
});

test('il corpo dell\'ordine ha solo box e quantità, più la casella accettata', () => {
  let cart = addToCart(EMPTY_CART, shopA, box(10), 2).cart;
  cart = addToCart(cart, shopA, box(11), 1).cart;

  assert.deepEqual(toOrderPayload(cart, true), {
    items: [{ box_id: 10, quantity: 2 }, { box_id: 11, quantity: 1 }],
    accept_no_refund: true,
  });
  assert.equal(toOrderPayload(cart, false).accept_no_refund, false);
});

// ---------- riallineamento con il negozio ----------

const shopData = (boxes, shop = shopA) => ({ shop, boxes });

test('syncWithShop non cambia nulla se tutto è come prima', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10), 2).cart;
  const { cart: synced, changes } = syncWithShop(cart, shopData([box(10)]));

  assert.deepEqual(changes, []);
  assert.deepEqual(synced.items.map((i) => [i.box_id, i.quantity]), [[10, 2]]);
});

test('syncWithShop toglie box eliminate, esaurite o non più ordinabili e lo dice', () => {
  let cart = addToCart(EMPTY_CART, shopA, box(10), 1).cart;
  cart = addToCart(cart, shopA, box(11), 1).cart;
  cart = addToCart(cart, shopA, box(12), 1).cart;
  cart = addToCart(cart, shopA, box(13), 1).cart;

  const { cart: synced, changes } = syncWithShop(cart, shopData([
    box(11, { sold_out: true, stock_available: 0, orderable: false }),
    box(12, { orderable: false }),
    box(13),
  ]));

  assert.deepEqual(synced.items.map((i) => i.box_id), [13]);
  assert.equal(changes.length, 3);
  assert.match(changes[0], /Box 10.*non è più in vendita/);
  assert.match(changes[1], /Box 11.*esaurita/);
  assert.match(changes[2], /Box 12.*orario limite/);
});

test('syncWithShop riduce la quantità se lo stock è sceso e avvisa del prezzo cambiato', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10), 4).cart;
  const { cart: synced, changes } = syncWithShop(cart, shopData([box(10, { stock_available: 2, price_cents: 800 })]));

  assert.equal(quantityInCart(synced, 10), 2);
  assert.equal(synced.items[0].price_cents, 800);
  assert.equal(changes.length, 2);
  assert.match(changes[0], /ne restano solo 2/);
  assert.match(changes[1], /da 7,00 € a 8,00 €/);
});

test('syncWithShop svuota il carrello se non resta nulla e ignora un altro negozio', () => {
  const cart = addToCart(EMPTY_CART, shopA, box(10), 1).cart;

  const emptied = syncWithShop(cart, shopData([]));
  assert.equal(isEmpty(emptied.cart), true);
  assert.equal(emptied.cart.shop, null);

  const other = syncWithShop(cart, shopData([box(10)], shopB));
  assert.equal(other.cart, cart);
  assert.deepEqual(other.changes, []);
});

// ---------- ordini ----------

test('timeOf legge l\'ora di Roma dall\'orario con fuso', () => {
  assert.equal(timeOf('2026-06-15T17:00:00+02:00'), '17:00');
  assert.equal(timeOf('2026-11-04T21:30:00+01:00'), '21:30');
});

test('formatCountdown mostra minuti e secondi e non va sotto zero', () => {
  assert.equal(formatCountdown(600000), '10:00');
  assert.equal(formatCountdown(61000), '01:01');
  assert.equal(formatCountdown(59001), '01:00');   // arrotonda per eccesso
  assert.equal(formatCountdown(0), '00:00');
  assert.equal(formatCountdown(-5000), '00:00');
});

test('statusInfo dà etichetta e tono, anche per stati sconosciuti', () => {
  assert.deepEqual(statusInfo('PAID'), { label: 'Pagato', tone: 'ok' });
  assert.equal(statusInfo('PENDING_PAYMENT').tone, 'warn');
  assert.equal(statusInfo('REFUNDED_FULL').label, 'Rimborsato');
  assert.deepEqual(statusInfo('NUOVO_STATO'), { label: 'NUOVO_STATO', tone: 'muted' });
});

test('shortageLines scrive quali box mancano con il nome del carrello', () => {
  const cart = addToCart(addToCart(EMPTY_CART, shopA, box(10), 3).cart, shopA, box(11), 1).cart;
  const err = { data: { items: [{ box_id: 10, requested: 3, available: 1 }, { box_id: 11, requested: 1, available: 0 }, { box_id: 99, requested: 2, available: 1 }] } };

  assert.deepEqual(shortageLines(err, cart), [
    '«Box 10»: ne volevi 3, ne restano 1.',
    '«Box 11»: è esaurita.',
    '«Una box»: ne volevi 2, ne restano 1.',
  ]);
  assert.deepEqual(shortageLines({}, cart), []);
});

test('paymentOutcomeText spiega gli esiti diversi da PAID', () => {
  assert.match(paymentOutcomeText('PAYMENT_FAILED'), /non è riuscito/);
  assert.match(paymentOutcomeText('REFUNDED_FULL'), /rimborsato per intero/);
  assert.match(paymentOutcomeText('EXPIRED'), /scaduto/);
  assert.match(paymentOutcomeText('ALTRO'), /Riprova/);
});

test('formatDate scrive la data in formato italiano', () => {
  assert.equal(formatDate('2026-06-15'), '15/06/2026');
  assert.equal(formatDate('2026-11-04T10:00:00+01:00'), '04/11/2026');
});

test('localTimeOf dà ore e minuti con due cifre', () => {
  assert.match(localTimeOf('2026-06-15T08:10:00+00:00'), /^\d{2}:\d{2}$/);
  assert.match(localTimeOf('2026-06-15T00:05:00Z'), /^\d{2}:\d{2}$/);
});
