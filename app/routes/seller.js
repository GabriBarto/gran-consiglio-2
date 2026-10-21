import { query } from '../db.js';
import { requireUser } from '../auth.js';
import { HttpError, euroToCents, int, readJson, route, str } from '../lib/http.js';
import { OFFER_SELECT } from './public.js';

/** Utente venditore + negozio approvato (solo allora si può pubblicare). */
async function sellerShop(req, { mustBeApproved = false } = {}) {
  const user = await requireUser(req, ['venditore']);
  const { rows: [shop] } = await query('SELECT * FROM shops WHERE owner_id = $1 ORDER BY id LIMIT 1', [user.id]);
  if (!shop) throw new HttpError(403, 'Completa prima il profilo della tua attività');
  if (mustBeApproved && shop.license_status !== 'approved') throw new HttpError(403, 'La tua attività non è ancora stata verificata');
  return shop;
}

route('GET', '/api/seller/dashboard', async ({ req }) => {
  const shop = await sellerShop(req);
  // senza il filtro "approved" di OFFER_SELECT: il venditore vede anche le proprie box non ancora visibili
  const offers = (await query(`${OFFER_SELECT.replace(`AND s.license_status = 'approved'`, '')} AND b.shop_id = $1 ORDER BY b.pickup_end DESC`, [shop.id])).rows;
  const reservations = (await query(`
    SELECT o.code, COALESCE(u.username, o.guest_name) AS name, SUM(i.qty)::int AS qty, o.status, string_agg(b.title, ', ') AS title
    FROM orders o JOIN order_items i ON i.order_id = o.id JOIN boxes b ON b.id = i.box_id LEFT JOIN users u ON u.id = o.user_id
    WHERE o.shop_id = $1 GROUP BY o.id, u.username ORDER BY o.id DESC LIMIT 30`, [shop.id])).rows;
  const { rows: [t] } = await query(`
    SELECT COALESCE(SUM(i.qty), 0)::int AS saved, COALESCE(SUM(i.qty * (i.unit_price_cents - i.unit_fee_cents)), 0)::int AS revenue
    FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.shop_id = $1 AND o.status <> 'cancelled'`, [shop.id]);
  return { shop: { id: shop.id, name: shop.name, emoji: shop.emoji, license_status: shop.license_status }, offers, reservations, plantsSaved: t.saved, revenueCents: t.revenue };
});

route('POST', '/api/seller/offers', async ({ req }) => {
  const shop = await sellerShop(req, { mustBeApproved: true });
  const b = await readJson(req);
  const title = str(b.title, 80);
  const full = euroToCents(b.original_price), price = euroToCents(b.price);
  const quantity = int(b.quantity), hours = Number(b.pickup_hours);
  const allergens = Array.isArray(b.allergens) ? b.allergens.map((a) => str(a, 40)).filter(Boolean).slice(0, 14) : [];
  if (!title) throw new HttpError(400, 'Inserisci un titolo');
  if (!(full > 0 && price > 0 && price < full)) throw new HttpError(400, 'Il prezzo di salvataggio deve essere inferiore al prezzo pieno');
  if (!(quantity >= 1 && quantity <= 99)) throw new HttpError(400, 'Quantità non valida');
  if (!(hours > 0 && hours <= 72)) throw new HttpError(400, 'Finestra di ritiro non valida');
  const now = Date.now();
  const { rows: [box] } = await query(`INSERT INTO boxes
    (shop_id, title, description, category, box_type, emoji, allergens, list_price_cents, price_cents, quantity, pickup_start, pickup_end)
    VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING id`,
    [shop.id, title, str(b.description, 400), str(b.category, 30) || 'piante', str(b.box_type, 30) || 'sorpresa', str(b.emoji, 8) || '🪴',
      allergens, full, price, quantity, new Date(now), new Date(now + hours * 3600e3)]);
  return { id: box.id };
});

route('POST', '/api/seller/redeem', async ({ req }) => {
  const shop = await sellerShop(req);
  const b = await readJson(req);
  const { rows: [o] } = await query(`
    SELECT o.id, o.status, COALESCE(u.username, o.guest_name) AS name,
           (SELECT SUM(qty)::int FROM order_items WHERE order_id = o.id) AS qty,
           (SELECT string_agg(bx.title, ', ') FROM order_items i JOIN boxes bx ON bx.id = i.box_id WHERE i.order_id = o.id) AS title
    FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.code = $1 AND o.shop_id = $2`, [str(b.code, 20).toUpperCase(), shop.id]);
  if (!o) throw new HttpError(404, 'Codice non valido per questo negozio');
  if (o.status === 'collected') throw new HttpError(409, 'Questo codice è già stato ritirato');
  if (o.status === 'cancelled') throw new HttpError(409, 'Questo ordine è stato annullato');
  await query("UPDATE orders SET status = 'collected' WHERE id = $1", [o.id]);
  return { name: o.name, qty: o.qty, title: o.title };
});
