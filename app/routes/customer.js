import crypto from 'node:crypto';
import { query, tx } from '../db.js';
import { requireUser } from '../auth.js';
import { HttpError, int, readJson, route } from '../lib/http.js';
import { charge } from '../lib/payment.js';
import { orderConfirmation, sendMail } from '../lib/mailer.js';
import { SERVICE_FEE_CENTS } from './public.js';

const MAX_PER_BOX = 5;
const customer = (req) => requireUser(req, ['cliente']);

// ---------- carrello (un solo negozio per volta) ----------
async function cartOf(userId, db = { query }) {
  const { rows } = await db.query(`
    SELECT b.id AS box_id, b.title, b.emoji, b.price_cents, b.list_price_cents AS original_price_cents, c.qty,
           (b.quantity - b.reserved) AS available, b.pickup_start, b.pickup_end,
           s.id AS shop_id, s.name AS shop_name, s.address, s.city
    FROM cart_items c JOIN boxes b ON b.id = c.box_id JOIN shops s ON s.id = b.shop_id
    WHERE c.user_id = $1 ORDER BY c.created_at, b.id`, [userId]);
  const now = Date.now();
  const items = rows.map((r) => ({
    ...r,
    ok: r.qty <= r.available && new Date(r.pickup_end) > now,   // false = esaurita o ritiro terminato: va sistemata prima di pagare
  }));
  return {
    shop: rows[0] ? { id: rows[0].shop_id, name: rows[0].shop_name, address: rows[0].address, city: rows[0].city } : null,
    items,
    total_cents: items.reduce((t, i) => t + i.qty * i.price_cents, 0),
    savings_cents: items.reduce((t, i) => t + i.qty * (i.original_price_cents - i.price_cents), 0),
  };
}

route('GET', '/api/cart', async ({ req }) => cartOf((await customer(req)).id));

// imposta la quantità di una box nel carrello (0 = rimuovi)
route('POST', '/api/cart/items', async ({ req }) => {
  const user = await customer(req);
  const b = await readJson(req);
  const boxId = int(b.box_id), qty = int(b.qty);
  if (!(qty >= 0 && qty <= MAX_PER_BOX)) throw new HttpError(400, `Puoi prendere da 1 a ${MAX_PER_BOX} sorprese per tipo`);
  await tx(async (db) => {
    if (qty === 0) { await db.query('DELETE FROM cart_items WHERE user_id = $1 AND box_id = $2', [user.id, boxId]); return; }
    const { rows: [box] } = await db.query(`
      SELECT b.*, s.license_status FROM boxes b JOIN shops s ON s.id = b.shop_id WHERE b.id = $1 AND b.deleted_at IS NULL`, [boxId]);
    if (!box || box.license_status !== 'approved') throw new HttpError(404, 'Offerta non trovata');
    if (new Date(box.pickup_end) <= new Date()) throw new HttpError(409, 'Il ritiro per questa offerta è terminato');
    if (qty > box.quantity - box.reserved) throw new HttpError(409, `Disponibili solo ${box.quantity - box.reserved} sorprese`);
    const { rows: other } = await db.query(
      'SELECT 1 FROM cart_items c JOIN boxes x ON x.id = c.box_id WHERE c.user_id = $1 AND x.shop_id <> $2 LIMIT 1', [user.id, box.shop_id]);
    if (other.length) {
      if (!b.replace) throw new HttpError(409, 'Il carrello contiene box di un altro negozio: si può ordinare da un solo negozio alla volta', 'other_shop');
      await db.query('DELETE FROM cart_items WHERE user_id = $1', [user.id]);
    }
    await db.query(`INSERT INTO cart_items (user_id, box_id, qty) VALUES ($1,$2,$3)
      ON CONFLICT (user_id, box_id) DO UPDATE SET qty = EXCLUDED.qty`, [user.id, boxId, qty]);
  });
  return cartOf(user.id);
});

route('DELETE', '/api/cart', async ({ req }) => {
  const user = await customer(req);
  await query('DELETE FROM cart_items WHERE user_id = $1', [user.id]);
  return cartOf(user.id);
});

// ---------- acquisto ----------
route('POST', '/api/checkout', async ({ req }) => {
  const user = await customer(req);
  const b = await readJson(req);
  return tx(async (db) => {
    // blocca le box in ordine di id (niente deadlock) e ricontrolla la disponibilità: vince chi arriva prima
    const { rows: items } = await db.query(`
      SELECT b.id AS box_id, b.title, b.shop_id, b.price_cents, b.quantity, b.reserved, b.pickup_start, b.pickup_end, c.qty
      FROM cart_items c JOIN boxes b ON b.id = c.box_id
      WHERE c.user_id = $1 AND b.deleted_at IS NULL ORDER BY b.id FOR UPDATE OF b`, [user.id]);
    if (!items.length) throw new HttpError(400, 'Il carrello è vuoto');
    for (const i of items) {
      if (new Date(i.pickup_end) <= new Date()) throw new HttpError(409, `Il ritiro di "${i.title}" è terminato`);
      if (i.quantity - i.reserved < i.qty) throw new HttpError(409, `"${i.title}" non ha abbastanza sorprese disponibili`);
    }
    const shopId = items[0].shop_id;
    const total = items.reduce((t, i) => t + i.qty * i.price_cents, 0);
    const unitFee = (price) => Math.min(SERVICE_FEE_CENTS, price);
    const fee = items.reduce((t, i) => t + i.qty * unitFee(i.price_cents), 0);

    const payment = await charge({ amountCents: total, card: b.card });

    const code = `VS-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    const { rows: [order] } = await db.query(
      `INSERT INTO orders (code, shop_id, user_id, status, total_cents, fee_cents, paid_at, payment_ref)
       VALUES ($1,$2,$3,'paid',$4,$5, now(), $6) RETURNING id, code, total_cents`, [code, shopId, user.id, total, fee, payment.ref]);
    for (const i of items) {
      await db.query('INSERT INTO order_items (order_id, box_id, qty, unit_price_cents, unit_fee_cents) VALUES ($1,$2,$3,$4,$5)',
        [order.id, i.box_id, i.qty, i.price_cents, unitFee(i.price_cents)]);
      await db.query('UPDATE boxes SET reserved = reserved + $1 WHERE id = $2', [i.qty, i.box_id]);
    }
    await db.query('DELETE FROM cart_items WHERE user_id = $1', [user.id]);

    const { rows: [shop] } = await db.query('SELECT name, address, city FROM shops WHERE id = $1', [shopId]);
    await sendMail(db, { to: user.email, orderId: order.id, ...orderConfirmation({ order, shop, items: items.map((i) => ({ ...i, unit_price_cents: i.price_cents })), username: user.username }) });
    return { code: order.code, total_cents: order.total_cents };
  });
});

// ---------- i miei ordini ----------
const ORDER_SELECT = `
  SELECT o.id, o.code, o.status, o.total_cents, o.created_at, o.paid_at, s.id AS shop_id, s.name AS shop_name, s.address, s.city,
         to_char(s.pickup_start, 'HH24:MI') AS usual_pickup_start, to_char(s.pickup_end, 'HH24:MI') AS usual_pickup_end,
         (SELECT json_agg(json_build_object('title', b.title, 'emoji', b.emoji, 'qty', i.qty, 'unit_price_cents', i.unit_price_cents,
                                            'pickup_start', b.pickup_start, 'pickup_end', b.pickup_end) ORDER BY i.id)
          FROM order_items i JOIN boxes b ON b.id = i.box_id WHERE i.order_id = o.id) AS items
  FROM orders o JOIN shops s ON s.id = o.shop_id`;

route('GET', '/api/orders', async ({ req }) => {
  const user = await customer(req);
  return (await query(`${ORDER_SELECT} WHERE o.user_id = $1 ORDER BY o.id DESC`, [user.id])).rows;
});

route('GET', '/api/orders/([A-Za-z0-9-]+)', async ({ req, match }) => {
  const user = await customer(req);
  const { rows: [o] } = await query(`${ORDER_SELECT} WHERE o.code = $1 AND o.user_id = $2`, [match[0].toUpperCase(), user.id]);
  if (!o) throw new HttpError(404, 'Ordine non trovato');
  return o;
});
