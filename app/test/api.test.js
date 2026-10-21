// Test di integrazione: servono un PostgreSQL raggiungibile via DATABASE_URL (database dedicato ai test).
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { pool, migrate, waitForDb } from '../db.js';
import { seedDemo, DEMO_PASSWORD, DEMO_ADMIN } from '../seed.js';
import { server } from '../app.js';

let base;
before(async () => {
  await waitForDb(); await migrate();
  await seedDemo({ reset: true });
  await new Promise((r) => server.listen(0, r));
  base = `http://localhost:${server.address().port}`;
});
after(async () => { server.close(); await pool.end(); });

async function call(path, { method = 'GET', body, token } = {}) {
  const res = await fetch(base + path, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, data: await res.json() };
}
const login = (email, password = DEMO_PASSWORD) => call('/api/auth/login', { method: 'POST', body: { email, password } });
const valid = { username: 'nuova_utente', email: 'Nuova@Example.com', password: 'segreta123', password_confirm: 'segreta123', role: 'cliente' };

test('registrazione: crea account, normalizza l\'email e apre una sessione', async () => {
  const r = await call('/api/auth/register', { method: 'POST', body: valid });
  assert.equal(r.status, 200);
  assert.equal(r.data.user.email, 'nuova@example.com');
  assert.equal(r.data.user.role, 'cliente');
  const me = await call('/api/auth/me', { token: r.data.token });
  assert.equal(me.data.user.username, 'nuova_utente');
});

test('registrazione: rifiuta duplicati, password deboli o diverse, ruolo admin', async () => {
  assert.equal((await call('/api/auth/register', { method: 'POST', body: valid })).status, 409);
  const bad = (patch) => call('/api/auth/register', { method: 'POST', body: { ...valid, username: 'altro_utente', email: 'altro@example.com', ...patch } });
  assert.equal((await bad({ password: 'corta1', password_confirm: 'corta1' })).status, 400);
  assert.equal((await bad({ password: 'soloLettere', password_confirm: 'soloLettere' })).status, 400);
  assert.equal((await bad({ password_confirm: 'diversa123' })).status, 400);
  assert.equal((await bad({ role: 'admin' })).status, 400);
});

test('login: password errata, account sospeso, logout', async () => {
  assert.equal((await login('cliente@demo.it', 'sbagliata1')).status, 401);
  assert.equal((await login('inesistente@demo.it')).status, 401);
  const ok = await login('cliente@demo.it');
  assert.equal(ok.status, 200);
  await call('/api/auth/logout', { method: 'POST', token: ok.data.token });
  assert.equal((await call('/api/auth/me', { token: ok.data.token })).status, 401);

  await pool.query(`UPDATE users SET status = 'suspended' WHERE email = 'cliente@demo.it'`);
  assert.equal((await login('cliente@demo.it')).status, 403);
  await pool.query(`UPDATE users SET status = 'active' WHERE email = 'cliente@demo.it'`);
});

test('ruoli: un cliente non accede all\'area venditore, un venditore sì', async () => {
  const cliente = await login('cliente@demo.it');
  assert.equal((await call('/api/seller/dashboard', { token: cliente.data.token })).status, 403);
  assert.equal((await call('/api/seller/dashboard')).status, 401);
  const seller = await login('glicine@demo.it');
  assert.equal(seller.data.shop.name, 'Vivaio Il Glicine');
  assert.equal((await call('/api/seller/dashboard', { token: seller.data.token })).status, 200);
});

test('admin: password da cambiare al primo accesso, poi il flag sparisce', async () => {
  const a = await login(DEMO_ADMIN.email, DEMO_ADMIN.password);
  assert.equal(a.data.user.must_change_password, true);
  const weak = await call('/api/auth/change-password', { method: 'POST', token: a.data.token, body: { current_password: DEMO_ADMIN.password, new_password: 'abc', new_password_confirm: 'abc' } });
  assert.equal(weak.status, 400);
  const ok = await call('/api/auth/change-password', { method: 'POST', token: a.data.token, body: { current_password: DEMO_ADMIN.password, new_password: 'nuovaPass99', new_password_confirm: 'nuovaPass99' } });
  assert.equal(ok.status, 200);
  const again = await login(DEMO_ADMIN.email, 'nuovaPass99');
  assert.equal(again.data.user.must_change_password, false);
});

test('un venditore appena registrato non può pubblicare finché non ha un negozio verificato', async () => {
  const r = await call('/api/auth/register', { method: 'POST', body: { ...valid, username: 'nuovo_negozio', email: 'negozio@example.com', role: 'venditore' } });
  assert.equal(r.data.shop, null);
  const pub = await call('/api/seller/offers', { method: 'POST', token: r.data.token, body: { title: 'x', original_price: 10, price: 5, quantity: 1, pickup_hours: 2 } });
  assert.equal(pub.status, 403);
});

const CARD = { number: '4242 4242 4242 4242', expiry: '12/30' };
const addToCart = (token, box_id, qty = 1, extra = {}) => call('/api/cart/items', { method: 'POST', token, body: { box_id, qty, ...extra } });
const publish = (token, title, quantity = 2, price = 8) =>
  call('/api/seller/offers', { method: 'POST', token, body: { title, original_price: 20, price, quantity, pickup_hours: 3, allergens: ['polline'] } });

test('flusso completo: pubblica, carrello, paga, email di conferma, ritira', async () => {
  const seller = await login('glicine@demo.it');
  const t = seller.data.token;
  const created = await publish(t, 'Box di test');
  assert.equal(created.status, 200);
  const id = created.data.id;
  assert.equal((await call('/api/seller/offers', { method: 'POST', token: t, body: { title: 'x', original_price: 5, price: 9, quantity: 1, pickup_hours: 1 } })).status, 400);

  const cliente = await login('cliente@demo.it');
  const c = cliente.data.token;
  assert.equal((await addToCart(c, id, 3)).status, 409, 'più delle box disponibili');
  const cart = await addToCart(c, id, 2);
  assert.equal(cart.data.total_cents, 1600);
  assert.equal(cart.data.shop.name, 'Vivaio Il Glicine');

  assert.equal((await call('/api/checkout', { method: 'POST', token: c, body: { card: { number: '4000000000000002', expiry: '12/30' } } })).status, 402);
  assert.equal((await call('/api/cart', { token: c })).data.items.length, 1, 'il pagamento rifiutato non svuota il carrello');
  assert.equal((await call('/api/checkout', { method: 'POST', token: c, body: { card: { number: '1234', expiry: '12/30' } } })).status, 400);

  const paid = await call('/api/checkout', { method: 'POST', token: c, body: { card: CARD } });
  assert.equal(paid.status, 200);
  const code = paid.data.code;
  assert.equal((await call('/api/cart', { token: c })).data.items.length, 0);

  const { rows: [mail] } = await pool.query(`SELECT e.* FROM emails e JOIN orders o ON o.id = e.order_id WHERE o.code = $1`, [code]);
  assert.equal(mail.to_email, 'cliente@demo.it');
  assert.match(mail.body, new RegExp(code));
  assert.match(mail.body, /Via delle Cascine 12/);

  const order = await call(`/api/orders/${code}`, { token: c });
  assert.equal(order.data.status, 'paid');
  assert.equal(order.data.items[0].qty, 2);
  assert.equal((await call('/api/orders', { token: c })).data[0].code, code);
  const stranger = await register('altro_cliente', 'altro.cliente@example.com');
  assert.equal((await call(`/api/orders/${code}`, { token: stranger.data.token })).status, 404, 'ordine di un altro utente');

  assert.equal((await addToCart(c, id, 1)).status, 409, 'box esaurite');

  const other = await login('fiori@demo.it');
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: other.data.token, body: { code } })).status, 404, 'un altro negozio non può ritirare');
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: t, body: { code } })).status, 200);
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: t, body: { code } })).status, 409);
  assert.ok((await call('/api/seller/dashboard', { token: t })).data.revenueCents > 0);
});

const register = (username, email, role = 'cliente') =>
  call('/api/auth/register', { method: 'POST', body: { ...valid, username, email, role } });

test('acquisto: serve un account cliente (niente prenotazioni da ospite)', async () => {
  assert.equal((await call('/api/cart')).status, 401);
  assert.equal((await call('/api/checkout', { method: 'POST', body: { card: CARD } })).status, 401);
  assert.equal((await call('/api/reservations', { method: 'POST', body: { offer_id: 1, name: 'A', email: 'a@example.com' } })).status, 404);
  const seller = await login('glicine@demo.it');
  assert.equal((await call('/api/cart', { token: seller.data.token })).status, 403);
});

test('carrello: un solo negozio per volta, con possibilità di sostituirlo', async () => {
  const u = await register('carrello_user', 'carrello@example.com');
  const t = u.data.token;
  const [a, b] = await Promise.all(['glicine@demo.it', 'fiori@demo.it'].map(async (e) => publish((await login(e)).data.token, `Box ${e}`, 5)));
  assert.equal((await addToCart(t, a.data.id, 2)).status, 200);
  const blocked = await addToCart(t, b.data.id, 1);
  assert.equal(blocked.status, 409);
  assert.equal(blocked.data.code, 'other_shop');
  const swapped = await addToCart(t, b.data.id, 1, { replace: true });
  assert.equal(swapped.data.items.length, 1);
  assert.equal(swapped.data.shop.name, 'Fiori & Fiori di Marta');
  assert.equal((await addToCart(t, b.data.id, 6)).status, 400);
  assert.equal((await addToCart(t, b.data.id, 0)).data.items.length, 0, 'quantità 0 rimuove');
});

test('checkout concorrente: non vende più box di quelle disponibili', async () => {
  const seller = await login('bosso@demo.it');
  const { data } = await publish(seller.data.token, 'Ultima box', 1);
  const users = await Promise.all(Array.from({ length: 5 }, (_, i) => register(`gara_${i}`, `gara${i}@example.com`)));
  await Promise.all(users.map((u) => addToCart(u.data.token, data.id, 1)));
  const results = await Promise.all(users.map((u) => call('/api/checkout', { method: 'POST', token: u.data.token, body: { card: CARD } })));
  assert.equal(results.filter((r) => r.status === 200).length, 1);
  assert.equal(results.filter((r) => r.status === 409).length, 4);
});

test('checkout: ritiro terminato e carrello vuoto', async () => {
  const u = await register('ritardo_user', 'ritardo@example.com');
  assert.equal((await call('/api/checkout', { method: 'POST', token: u.data.token, body: { card: CARD } })).status, 400);
  const seller = await login('succulente@demo.it');
  const { data } = await publish(seller.data.token, 'Scade subito');
  await addToCart(u.data.token, data.id, 1);
  await pool.query(`UPDATE boxes SET pickup_end = now() - interval '1 minute' WHERE id = $1`, [data.id]);
  assert.equal((await call('/api/checkout', { method: 'POST', token: u.data.token, body: { card: CARD } })).status, 409);
  assert.equal((await call('/api/cart', { token: u.data.token })).data.items[0].ok, false);
});

test('ricerca negozi: per nome, città e distanza', async () => {
  const all = (await call('/api/shops')).data;
  assert.equal(all.length, 5);
  assert.equal(all[0].distance_km, undefined);

  assert.deepEqual((await call('/api/shops?q=glicine')).data.map((s) => s.name), ['Vivaio Il Glicine']);
  assert.deepEqual((await call('/api/shops?city=Prato')).data.map((s) => s.city), ['Prato', 'Prato']);

  // dal Duomo di Firenze: i negozi fiorentini vengono prima, Pistoia è la più lontana
  const near = (await call('/api/shops?lat=43.7731&lng=11.2560')).data;
  assert.equal(near[0].name, 'Fiori & Fiori di Marta');
  assert.ok(near[0].distance_km < 1);
  assert.equal(near.at(-1).city, 'Pistoia');
  assert.ok(near.every((s, i) => i === 0 || s.distance_km >= near[i - 1].distance_km));

  const within = (await call('/api/shops?lat=43.7731&lng=11.2560&max_km=10')).data;
  assert.deepEqual(within.map((s) => s.city).sort(), ['Firenze', 'Firenze']);
  assert.equal((await call('/api/shops?lat=999&lng=11')).data[0].distance_km, undefined, 'coordinate non valide ignorate');

  const offers = (await call('/api/offers?lat=43.9336&lng=10.9175')).data;
  assert.equal(offers[0].shop_name, 'Garden Verde Pistoia');
  assert.equal(offers[0].distance_km, 0);
});

test('pagina del negozio: indirizzo, orari e box', async () => {
  const [shop] = (await call('/api/shops?q=glicine')).data;
  const page = await call(`/api/shops/${shop.id}?lat=43.7731&lng=11.2560`);
  assert.equal(page.status, 200);
  assert.equal(page.data.address, 'Via delle Cascine 12');
  assert.equal(page.data.opening_time, '08:30');
  assert.equal(page.data.pickup_start, '17:30');
  assert.ok(page.data.distance_km > 0);
  assert.ok(page.data.boxes.length >= 3);
  assert.equal((await call('/api/shops/99999')).status, 404);
});
