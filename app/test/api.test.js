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

test('flusso completo: pubblica, prenota, controlla la disponibilità, ritira', async () => {
  const seller = await login('glicine@demo.it');
  const t = seller.data.token;
  const created = await call('/api/seller/offers', { method: 'POST', token: t, body: { title: 'Box di test', original_price: 20, price: 8, quantity: 2, pickup_hours: 3, allergens: ['polline'] } });
  assert.equal(created.status, 200);
  const id = created.data.id;

  assert.equal((await call('/api/seller/offers', { method: 'POST', token: t, body: { title: 'x', original_price: 5, price: 9, quantity: 1, pickup_hours: 1 } })).status, 400);

  const book = (qty) => call('/api/reservations', { method: 'POST', body: { offer_id: id, name: 'Anna', email: 'anna@example.com', qty } });
  const r1 = await book(2);
  assert.equal(r1.status, 200);
  assert.equal((await book(1)).status, 409, 'box esaurite');

  const res = await call(`/api/reservations/${r1.data.code}`);
  assert.equal(res.data.status, 'reserved');
  assert.equal(res.data.qty, 2);

  const other = await login('fiori@demo.it');
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: other.data.token, body: { code: r1.data.code } })).status, 404, 'un altro negozio non può ritirare');
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: t, body: { code: r1.data.code } })).status, 200);
  assert.equal((await call('/api/seller/redeem', { method: 'POST', token: t, body: { code: r1.data.code } })).status, 409);

  const dash = await call('/api/seller/dashboard', { token: t });
  assert.ok(dash.data.revenueCents > 0);
});

test('la prenotazione concorrente non vende più box di quelle disponibili', async () => {
  const seller = await login('bosso@demo.it');
  const { data } = await call('/api/seller/offers', { method: 'POST', token: seller.data.token, body: { title: 'Ultima box', original_price: 20, price: 8, quantity: 1, pickup_hours: 3 } });
  const results = await Promise.all(Array.from({ length: 5 }, () =>
    call('/api/reservations', { method: 'POST', body: { offer_id: data.id, name: 'Gara', email: 'g@example.com', qty: 1 } })));
  assert.equal(results.filter((r) => r.status === 200).length, 1);
});
