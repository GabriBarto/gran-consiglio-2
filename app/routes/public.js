import { query } from '../db.js';
import { HttpError, route, str } from '../lib/http.js';
import { positionFrom, withDistance } from '../lib/geo.js';

export const DEMO_MODE = process.env.DEMO_MODE !== 'false';
// tariffa fissa trattenuta dalla piattaforma per ogni box venduta (importo ancora da stabilire)
export const SERVICE_FEE_CENTS = Number(process.env.SERVICE_FEE_CENTS ?? 100);

export const OFFER_SELECT = `
  SELECT b.id, b.shop_id, b.title, b.description, b.category, b.box_type, b.emoji, b.allergens,
         b.list_price_cents AS original_price_cents, b.price_cents, b.quantity, b.reserved,
         (b.quantity - b.reserved) AS available, b.pickup_start, b.pickup_end,
         s.name AS shop_name, s.type AS shop_type, s.city, s.address, s.emoji AS shop_emoji, s.lat AS shop_lat, s.lng AS shop_lng
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
  return withDistance(rows, positionFrom(url));   // con ?lat=&lng= le offerte sono ordinate per distanza
});

route('GET', '/api/offers/(\\d+)', async ({ match }) => {
  const { rows } = await query(`${OFFER_SELECT} AND b.id = $1`, [Number(match[0])]);
  if (!rows[0]) throw new HttpError(404, 'Offerta non trovata');
  return rows[0];
});

const SHOP_SELECT = `
  SELECT s.id, s.name, s.type, s.description, s.emoji, s.phone, s.address, s.city, s.lat AS shop_lat, s.lng AS shop_lng,
         to_char(s.opening_time, 'HH24:MI') AS opening_time, to_char(s.closing_time, 'HH24:MI') AS closing_time,
         to_char(s.pickup_start, 'HH24:MI') AS pickup_start, to_char(s.pickup_end, 'HH24:MI') AS pickup_end,
         (SELECT COUNT(*)::int FROM boxes b WHERE b.shop_id = s.id AND b.deleted_at IS NULL
            AND b.pickup_end > now() AND b.quantity > b.reserved) AS open_boxes
  FROM shops s WHERE s.license_status = 'approved'`;

// ricerca negozi per nome/città; con ?lat=&lng= ordinati per distanza, con &max_km= solo quelli vicini
route('GET', '/api/shops', async ({ url }) => {
  const q = str(url.searchParams.get('q')).toLowerCase();
  const city = str(url.searchParams.get('city'));
  const maxKm = Number(url.searchParams.get('max_km'));
  const { rows } = await query(`${SHOP_SELECT} AND ($1 = '' OR s.city = $1)
    AND ($2 = '' OR lower(s.name || ' ' || s.city) LIKE '%' || $2 || '%') ORDER BY s.name`, [city, q]);
  const pos = positionFrom(url);
  const list = withDistance(rows, pos);
  return pos && maxKm > 0 ? list.filter((s) => s.distance_km != null && s.distance_km <= maxKm) : list;
});

route('GET', '/api/shops/(\\d+)', async ({ url, match }) => {
  const { rows: [shop] } = await query(`${SHOP_SELECT} AND s.id = $1`, [Number(match[0])]);
  if (!shop) throw new HttpError(404, 'Negozio non trovato');
  const boxes = (await query(`${OFFER_SELECT} AND b.shop_id = $1 AND b.pickup_end > now() ORDER BY b.pickup_end`, [shop.id])).rows;
  const pos = positionFrom(url);
  return { ...shop, distance_km: pos ? withDistance([shop], pos)[0].distance_km : null, boxes };
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

// Consultazione di un ordine tramite codice (usata dall'app mobile)
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
