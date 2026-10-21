import crypto from 'node:crypto';
import { query, tx } from '../db.js';
import { HttpError, int, readJson, route, str } from '../lib/http.js';

export const DEMO_MODE = process.env.DEMO_MODE !== 'false';
// tariffa fissa trattenuta dalla piattaforma per ogni box venduta (importo ancora da stabilire)
export const SERVICE_FEE_CENTS = Number(process.env.SERVICE_FEE_CENTS ?? 100);

export const OFFER_SELECT = `
  SELECT b.id, b.shop_id, b.title, b.description, b.category, b.box_type, b.emoji, b.allergens,
         b.list_price_cents AS original_price_cents, b.price_cents, b.quantity, b.reserved,
         (b.quantity - b.reserved) AS available, b.pickup_start, b.pickup_end,
         s.name AS shop_name, s.type AS shop_type, s.city, s.address, s.emoji AS shop_emoji
  FROM boxes b JOIN shops s ON s.id = b.shop_id
  WHERE b.deleted_at IS NULL AND s.license_status = 'approved'`;

route('GET', '/health', async () => { await query('SELECT 1'); return { status: 'ok' }; });

route('GET', '/api/config', () => ({ demo: DEMO_MODE }));

route('GET', '/api/offers', async ({ url }) => {
  const q = str(url.searchParams.get('q')).toLowerCase();
  const category = str(url.searchParams.get('category'));
  const city = str(url.searchParams.get('city'));
  const { rows } = await query(`${OFFER_SELECT} AND b.pickup_end > now() AND b.quantity > b.reserved
    AND ($1 = '' OR b.category = $1) AND ($2 = '' OR s.city = $2)
    AND ($3 = '' OR lower(b.title || ' ' || b.description || ' ' || s.name) LIKE '%' || $3 || '%')
    ORDER BY b.pickup_end ASC`, [category, city, q]);
  return rows;
});

route('GET', '/api/offers/(\\d+)', async ({ match }) => {
  const { rows } = await query(`${OFFER_SELECT} AND b.id = $1`, [Number(match[0])]);
  if (!rows[0]) throw new HttpError(404, 'Offerta non trovata');
  return rows[0];
});

route('GET', '/api/filters', async () => ({
  cities: (await query(`SELECT DISTINCT city FROM shops WHERE license_status = 'approved' ORDER BY city`)).rows.map((r) => r.city),
  categories: (await query('SELECT DISTINCT category FROM boxes WHERE deleted_at IS NULL ORDER BY category')).rows.map((r) => r.category),
}));

route('GET', '/api/stats', async () => {
  const { rows: [r] } = await query(`
    SELECT COALESCE(SUM(i.qty), 0)::int AS saved,
           COALESCE(SUM(i.qty * (b.list_price_cents - i.unit_price_cents)), 0)::int AS discount,
           COALESCE(SUM(i.qty * (i.unit_price_cents - i.unit_fee_cents)), 0)::int AS revenue
    FROM order_items i JOIN orders o ON o.id = i.order_id JOIN boxes b ON b.id = i.box_id
    WHERE o.status <> 'cancelled'`);
  const shops = (await query(`SELECT COUNT(*)::int AS c FROM shops WHERE license_status = 'approved'`)).rows[0].c;
  return { plantsSaved: r.saved, shops, moneySavedCents: r.discount, sellerRevenueCents: r.revenue };
});

// Prenotazione senza account (temporanea: dalla Fase 1 l'acquisto richiede la registrazione)
route('POST', '/api/reservations', async ({ req }) => {
  const b = await readJson(req);
  const boxId = int(b.offer_id), qty = int(b.qty ?? 1);
  const name = str(b.name, 80), email = str(b.email, 120).toLowerCase();
  if (!name) throw new HttpError(400, 'Inserisci il tuo nome');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new HttpError(400, 'Email non valida');
  if (!(qty >= 1 && qty <= 5)) throw new HttpError(400, 'Puoi prenotare da 1 a 5 sorprese');
  return tx(async (db) => {
    const { rows: [box] } = await db.query('SELECT * FROM boxes WHERE id = $1 AND deleted_at IS NULL FOR UPDATE', [boxId]);
    if (!box) throw new HttpError(404, 'Offerta non trovata');
    if (new Date(box.pickup_end) <= new Date()) throw new HttpError(409, 'Il ritiro per questa offerta è terminato');
    if (box.quantity - box.reserved < qty) throw new HttpError(409, 'Non ci sono abbastanza sorprese disponibili');
    const unitFee = Math.min(SERVICE_FEE_CENTS, box.price_cents);
    const code = `VS-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    const { rows: [order] } = await db.query(
      `INSERT INTO orders (code, shop_id, guest_name, guest_email, total_cents, fee_cents) VALUES ($1,$2,$3,$4,$5,$6) RETURNING id`,
      [code, box.shop_id, name, email, qty * box.price_cents, qty * unitFee]);
    await db.query('INSERT INTO order_items (order_id, box_id, qty, unit_price_cents, unit_fee_cents) VALUES ($1,$2,$3,$4,$5)',
      [order.id, boxId, qty, box.price_cents, unitFee]);
    await db.query('UPDATE boxes SET reserved = reserved + $1 WHERE id = $2', [qty, boxId]);
    return { code };
  });
});

route('GET', '/api/reservations/([A-Za-z0-9-]+)', async ({ match }) => {
  const { rows } = await query(`
    SELECT o.code, COALESCE(u.username, o.guest_name) AS name, i.qty, o.status, b.title, b.emoji, b.price_cents,
           b.pickup_start, b.pickup_end, s.name AS shop_name, s.address, s.city
    FROM orders o JOIN order_items i ON i.order_id = o.id JOIN boxes b ON b.id = i.box_id
    JOIN shops s ON s.id = o.shop_id LEFT JOIN users u ON u.id = o.user_id
    WHERE o.code = $1 LIMIT 1`, [match[0].toUpperCase()]);
  if (!rows[0]) throw new HttpError(404, 'Prenotazione non trovata');
  return rows[0];
});
