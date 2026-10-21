import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { db, tx } from './db.js';
import { seed } from './seed.js';

const PORT = Number(process.env.PORT || 3000);
const DEMO_MODE = process.env.DEMO_MODE !== 'false';
const PUBLIC_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), 'public');

if (seed({ reset: process.env.RESET_DEMO === 'true' })) console.log('Dati di demo caricati.');

// ---------- helpers ----------
class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
const sessions = new Map(); // token -> shop_id
const nowIso = () => new Date().toISOString();

const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };

// CORS aperto: serve all'app mobile (Expo) e alla versione web di sviluppo
const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, Authorization',
};

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...CORS });
  res.end(JSON.stringify(body));
}

async function readJson(req) {
  let raw = '';
  for await (const chunk of req) {
    raw += chunk;
    if (raw.length > 1e5) throw new HttpError(413, 'Richiesta troppo grande');
  }
  try { return raw ? JSON.parse(raw) : {}; } catch { throw new HttpError(400, 'JSON non valido'); }
}

function requireShop(req) {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  const shopId = sessions.get(token);
  if (!shopId) throw new HttpError(401, 'Accesso venditore richiesto');
  return shopId;
}

const str = (v, max = 200) => String(v ?? '').trim().slice(0, max);
const int = (v) => { const n = Number(v); return Number.isInteger(n) ? n : NaN; };
const euroToCents = (v) => Math.round(Number(v) * 100);

const OFFER_SELECT = `
  SELECT o.*, (o.quantity - o.reserved) AS available,
         s.name AS shop_name, s.type AS shop_type, s.city, s.address, s.emoji AS shop_emoji
  FROM offers o JOIN shops s ON s.id = o.shop_id`;

// ---------- routes ----------
const routes = [];
const route = (method, pattern, handler) => routes.push({ method, re: new RegExp(`^${pattern}$`), handler });

route('GET', '/health', () => ({ status: 'ok' }));

route('GET', '/api/config', () => ({ demo: DEMO_MODE }));

route('GET', '/api/offers', ({ url }) => {
  const q = str(url.searchParams.get('q')).toLowerCase();
  const category = str(url.searchParams.get('category'));
  const city = str(url.searchParams.get('city'));
  let rows = db.prepare(`${OFFER_SELECT} WHERE o.pickup_end > ? AND o.quantity > o.reserved ORDER BY o.pickup_end ASC`).all(nowIso());
  if (category) rows = rows.filter((r) => r.category === category);
  if (city) rows = rows.filter((r) => r.city === city);
  if (q) rows = rows.filter((r) => `${r.title} ${r.description} ${r.shop_name}`.toLowerCase().includes(q));
  return rows;
});

route('GET', '/api/offers/(\\d+)', ({ match }) => {
  const o = db.prepare(`${OFFER_SELECT} WHERE o.id = ?`).get(Number(match[0]));
  if (!o) throw new HttpError(404, 'Offerta non trovata');
  return o;
});

route('GET', '/api/filters', () => ({
  cities: db.prepare('SELECT DISTINCT city FROM shops ORDER BY city').all().map((r) => r.city),
  categories: db.prepare('SELECT DISTINCT category FROM offers ORDER BY category').all().map((r) => r.category),
}));

route('GET', '/api/stats', () => {
  const r = db.prepare(`SELECT COALESCE(SUM(r.qty),0) AS saved,
      COALESCE(SUM(r.qty * o.price_cents),0) AS revenue,
      COALESCE(SUM(r.qty * (o.original_price_cents - o.price_cents)),0) AS discount
    FROM reservations r JOIN offers o ON o.id = r.offer_id`).get();
  const shops = db.prepare('SELECT COUNT(*) AS c FROM shops').get().c;
  return { plantsSaved: r.saved, shops, moneySavedCents: r.discount, sellerRevenueCents: r.revenue };
});

route('POST', '/api/reservations', async ({ req }) => {
  const b = await readJson(req);
  const offerId = int(b.offer_id), qty = int(b.qty ?? 1);
  const name = str(b.name, 80), email = str(b.email, 120);
  if (!name) throw new HttpError(400, 'Inserisci il tuo nome');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new HttpError(400, 'Email non valida');
  if (!(qty >= 1 && qty <= 5)) throw new HttpError(400, 'Puoi prenotare da 1 a 5 sorprese');
  return tx(() => {
    const o = db.prepare('SELECT * FROM offers WHERE id = ?').get(offerId);
    if (!o) throw new HttpError(404, 'Offerta non trovata');
    if (o.pickup_end <= nowIso()) throw new HttpError(409, 'Il ritiro per questa offerta è terminato');
    if (o.quantity - o.reserved < qty) throw new HttpError(409, 'Non ci sono abbastanza sorprese disponibili');
    const code = `VS-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    db.prepare('UPDATE offers SET reserved = reserved + ? WHERE id = ?').run(qty, offerId);
    db.prepare('INSERT INTO reservations (offer_id,code,name,email,qty) VALUES (?,?,?,?,?)').run(offerId, code, name, email, qty);
    return { code };
  });
});

route('GET', '/api/reservations/([A-Za-z0-9-]+)', ({ match }) => {
  const r = db.prepare(`
    SELECT r.code, r.name, r.qty, r.status, o.title, o.emoji, o.price_cents, o.pickup_start, o.pickup_end,
           s.name AS shop_name, s.address, s.city
    FROM reservations r JOIN offers o ON o.id = r.offer_id JOIN shops s ON s.id = o.shop_id
    WHERE r.code = ?`).get(match[1].toUpperCase());
  if (!r) throw new HttpError(404, 'Prenotazione non trovata');
  return r;
});

// --- area venditore ---
route('GET', '/api/shops', () => db.prepare('SELECT id, name, type, city, emoji FROM shops ORDER BY name').all());

route('POST', '/api/seller/login', async ({ req }) => {
  const b = await readJson(req);
  const shop = db.prepare('SELECT * FROM shops WHERE id = ?').get(int(b.shop_id));
  if (!shop || shop.pin !== str(b.pin, 20)) throw new HttpError(401, 'PIN non corretto');
  const token = crypto.randomUUID();
  sessions.set(token, shop.id);
  return { token, shop: { id: shop.id, name: shop.name, emoji: shop.emoji, city: shop.city } };
});

route('GET', '/api/seller/dashboard', ({ req }) => {
  const shopId = requireShop(req);
  const offers = db.prepare(`${OFFER_SELECT} WHERE o.shop_id = ? ORDER BY o.pickup_end DESC`).all(shopId);
  const reservations = db.prepare(`
    SELECT r.code, r.name, r.qty, r.status, o.title FROM reservations r JOIN offers o ON o.id = r.offer_id
    WHERE o.shop_id = ? ORDER BY r.id DESC LIMIT 30`).all(shopId);
  const t = db.prepare(`SELECT COALESCE(SUM(r.qty),0) AS saved, COALESCE(SUM(r.qty*o.price_cents),0) AS revenue
    FROM reservations r JOIN offers o ON o.id = r.offer_id WHERE o.shop_id = ?`).get(shopId);
  return { offers, reservations, plantsSaved: t.saved, revenueCents: t.revenue };
});

route('POST', '/api/seller/offers', async ({ req }) => {
  const shopId = requireShop(req);
  const b = await readJson(req);
  const title = str(b.title, 80);
  const full = euroToCents(b.original_price), price = euroToCents(b.price);
  const quantity = int(b.quantity), hours = Number(b.pickup_hours);
  if (!title) throw new HttpError(400, 'Inserisci un titolo');
  if (!(full > 0 && price > 0 && price < full)) throw new HttpError(400, 'Il prezzo di salvataggio deve essere inferiore al prezzo pieno');
  if (!(quantity >= 1 && quantity <= 99)) throw new HttpError(400, 'Quantità non valida');
  if (!(hours > 0 && hours <= 72)) throw new HttpError(400, 'Finestra di ritiro non valida');
  const now = Date.now();
  const info = db.prepare(`INSERT INTO offers
    (shop_id,title,description,category,emoji,original_price_cents,price_cents,quantity,pickup_start,pickup_end)
    VALUES (?,?,?,?,?,?,?,?,?,?)`).run(shopId, title, str(b.description, 400), str(b.category, 30) || 'piante',
    str(b.emoji, 8) || '🪴', full, price, quantity, new Date(now).toISOString(), new Date(now + hours * 3600e3).toISOString());
  return { id: Number(info.lastInsertRowid) };
});

route('POST', '/api/seller/redeem', async ({ req }) => {
  const shopId = requireShop(req);
  const b = await readJson(req);
  const r = db.prepare(`SELECT r.id, r.status, r.qty, r.name, o.title FROM reservations r
    JOIN offers o ON o.id = r.offer_id WHERE r.code = ? AND o.shop_id = ?`).get(str(b.code, 20).toUpperCase(), shopId);
  if (!r) throw new HttpError(404, 'Codice non valido per questo negozio');
  if (r.status === 'collected') throw new HttpError(409, 'Questo codice è già stato ritirato');
  db.prepare("UPDATE reservations SET status = 'collected' WHERE id = ?").run(r.id);
  return { name: r.name, qty: r.qty, title: r.title };
});

if (DEMO_MODE) {
  route('POST', '/api/demo/reset', () => { seed({ reset: true }); sessions.clear(); return { ok: true }; });
}

// ---------- server ----------
function serveStatic(req, res, pathname) {
  const rel = pathname === '/' ? 'index.html' : pathname.slice(1);
  const file = path.normalize(path.join(PUBLIC_DIR, rel));
  if (!file.startsWith(PUBLIC_DIR) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    return send(res, 404, { error: 'Non trovato' });
  }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (req.method === 'OPTIONS') { res.writeHead(204, CORS); return res.end(); }
  try {
    for (const r of routes) {
      const match = r.method === req.method && r.re.exec(url.pathname);
      if (match) return send(res, 200, await r.handler({ req, url, match: match.slice(1) }));
    }
    if (url.pathname.startsWith('/api/')) throw new HttpError(404, 'Endpoint non trovato');
    if (req.method !== 'GET') throw new HttpError(405, 'Metodo non consentito');
    serveStatic(req, res, url.pathname);
  } catch (e) {
    if (!(e instanceof HttpError)) console.error(e);
    send(res, e.status || 500, { error: e.status ? e.message : 'Errore interno' });
  }
});

server.listen(PORT, '0.0.0.0', () => console.log(`VerdeSalva in ascolto su http://localhost:${PORT}`));
for (const sig of ['SIGTERM', 'SIGINT']) process.on(sig, () => server.close(() => process.exit(0)));
