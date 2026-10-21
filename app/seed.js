import { pool, query, tx, migrate, waitForDb } from './db.js';
import { hashPassword } from './auth.js';

const H = 3600 * 1000;
const at = (ms) => new Date(Date.now() + ms);

export const DEMO_PASSWORD = 'demo1234';
export const DEMO_ADMIN = { email: 'admin@demo.it', password: 'admin1234' };

// [email, username, nome, tipo, città, indirizzo, emoji, descrizione, lat, lng]
const shops = [
  ['glicine@demo.it', 'vivaio_glicine', 'Vivaio Il Glicine', 'vivaio', 'Firenze', 'Via delle Cascine 12', '🌳', 'Vivaio a conduzione familiare dal 1974. Piante da giardino e da terrazzo.', 43.7835, 11.2335],
  ['fiori@demo.it', 'fiori_di_marta', 'Fiori & Fiori di Marta', 'fiorista', 'Firenze', 'Via dei Servi 48', '💐', 'Bouquet e composizioni fresche ogni mattina.', 43.7742, 11.2594],
  ['garden@demo.it', 'garden_verde', 'Garden Verde Pistoia', 'garden', 'Pistoia', 'Viale Adua 210', '🏡', 'Garden center con serre, attrezzi e tanto verde.', 43.9336, 10.9175],
  ['succulente@demo.it', 'succulente_matte', 'Succulente Matte', 'vivaio', 'Prato', 'Via Pistoiese 7', '🌵', 'Specialisti di cactus e succulente da collezione.', 43.8808, 11.0770],
  ['bosso@demo.it', 'bosso_e_rosa', 'Il Bosso e la Rosa', 'fiorista', 'Prato', 'Piazza Mercatale 3', '🌹', 'Fiorista di quartiere, piante da interno e fiori recisi.', 43.8777, 11.1022],
];

// [negozio, titolo, descrizione, categoria, emoji, listino €, prezzo €, quantità, inizio ritiro (h), fine ritiro (h)]
const boxes = [
  [1, 'Sorpresa Aromatiche', 'Mix di 5 vasi di erbe aromatiche (basilico, rosmarino, salvia, menta, timo) a fine stagione ma ancora rigogliose.', 'erbe', '🌿', 25, 8.9, 6, 1, 6],
  [1, 'Lavanda & Rosmarino da giardino', 'Piante in vaso 18cm, leggermente sfiorite. Perfette per bordure.', 'piante', '💜', 30, 11.0, 4, 2, 8],
  [1, 'Agrumi in vaso (limone o mandarino)', "Piccoli alberelli con qualche foglia da sistemare. Sopravvivono benissimo con un po' di cure.", 'piante-da-frutto', '🍋', 45, 19.0, 3, 1, 5],
  [2, 'Bouquet del giorno prima', 'Fiori recisi freschi invenduti di ieri: ancora 7-10 giorni di vita in vaso.', 'fiori', '💐', 28, 9.0, 8, 0.5, 4],
  [2, 'Sorpresa Orchidea', 'Phalaenopsis con fiori aperti, vaso leggermente segnato.', 'piante', '🌸', 35, 14.0, 3, 1, 7],
  [3, 'Box Piante da interno', '3 piante verdi da interno assortite (pothos, sansevieria, zamioculcas), fine serie.', 'piante', '🪴', 48, 18.0, 5, 2, 9],
  [3, 'Cassetta Orto Autunnale', 'Piantine da orto pronte al trapianto: cavolo nero, bietola, lattuga, finocchio.', 'erbe', '🥬', 18, 6.5, 10, 1, 10],
  [4, 'Mix Succulente Mini', '8 mini succulente in vasetti da 6cm, perfette per un regalo.', 'succulente', '🌵', 32, 12.0, 7, 3, 11],
  [4, 'Cactus Grande Sorpresa', 'Un cactus adulto a sorpresa: lievemente scheggiato ma sano.', 'succulente', '🏜️', 40, 15.0, 2, 3, 10],
  [5, 'Ciclamini e Primule', 'Colore per il balcone: 6 piante da fiore stagionali.', 'fiori', '🌺', 24, 8.0, 5, 0.5, 3],
  [5, 'Kokedama Sorpresa', "Piantina avvolta nel muschio, già sospesa e pronta all'uso.", 'piante', '🎍', 29, 10.5, 4, 1, 6],
];

const TABLES = 'reviews, order_items, orders, boxes, licenses, shops, sessions, users';

export async function seedDemo({ reset = false } = {}) {
  const { rows: [{ c }] } = await query('SELECT COUNT(*)::int AS c FROM shops');
  if (c > 0 && !reset) return false;
  const pwd = await hashPassword(DEMO_PASSWORD);
  const adminPwd = await hashPassword(DEMO_ADMIN.password);
  await tx(async (db) => {
    if (reset) await db.query(`TRUNCATE ${TABLES} RESTART IDENTITY CASCADE`);
    await db.query(`INSERT INTO users (username, email, password_hash, role, must_change_password) VALUES ('admin', $1, $2, 'admin', TRUE)`, [DEMO_ADMIN.email, adminPwd]);
    await db.query(`INSERT INTO users (username, email, password_hash, role) VALUES ('cliente_demo', 'cliente@demo.it', $1, 'cliente')`, [pwd]);
    for (const [email, username, name, type, city, address, emoji, description, lat, lng] of shops) {
      const { rows: [u] } = await db.query(`INSERT INTO users (username, email, password_hash, role) VALUES ($1, $2, $3, 'venditore') RETURNING id`, [username, email, pwd]);
      await db.query(`INSERT INTO shops (owner_id, name, type, city, address, emoji, description, lat, lng, phone, opening_time, closing_time, pickup_start, pickup_end, license_status)
        VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,'055 000000','08:30','19:30','17:30','19:30','approved')`, [u.id, name, type, city, address, emoji, description, lat, lng]);
    }
    for (const [shop, title, description, category, emoji, full, price, quantity, from, to] of boxes) {
      await db.query(`INSERT INTO boxes (shop_id, title, description, category, emoji, list_price_cents, price_cents, quantity, pickup_start, pickup_end)
        VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`, [shop, title, description, category, emoji, Math.round(full * 100), Math.round(price * 100), quantity, at(from * H), at(to * H)]);
    }
    // qualche ritiro già completato, per rendere vive le statistiche: [box, codice, nome, qty]
    for (const [boxId, code, name, qty] of [[1, 'VS-DEMO1', 'Giulia R.', 2], [4, 'VS-DEMO2', 'Luca M.', 3], [6, 'VS-DEMO3', 'Sara T.', 1]]) {
      const { rows: [b] } = await db.query('SELECT shop_id, price_cents FROM boxes WHERE id = $1', [boxId]);
      const { rows: [o] } = await db.query(`INSERT INTO orders (code, shop_id, guest_name, guest_email, status, total_cents, fee_cents)
        VALUES ($1,$2,$3,$4,'collected',$5,$6) RETURNING id`, [code, b.shop_id, name, `${code.toLowerCase()}@example.com`, qty * b.price_cents, qty * 100]);
      await db.query('INSERT INTO order_items (order_id, box_id, qty, unit_price_cents, unit_fee_cents) VALUES ($1,$2,$3,$4,100)', [o.id, boxId, qty, b.price_cents]);
      await db.query('UPDATE boxes SET reserved = reserved + $1 WHERE id = $2', [qty, boxId]);
    }
  });
  return true;
}

/** Crea l'amministratore indicato da ADMIN_EMAIL / ADMIN_PASSWORD se non ne esiste uno (password da cambiare al primo accesso). */
export async function ensureAdmin() {
  const { ADMIN_EMAIL, ADMIN_PASSWORD } = process.env;
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) return;
  const { rows } = await query(`SELECT 1 FROM users WHERE role = 'admin' LIMIT 1`);
  if (rows.length) return;
  await query(`INSERT INTO users (username, email, password_hash, role, must_change_password) VALUES ('admin', $1, $2, 'admin', TRUE)`,
    [ADMIN_EMAIL.toLowerCase(), await hashPassword(ADMIN_PASSWORD)]);
  console.log('Account admin iniziale creato.');
}

if (process.argv[1]?.endsWith('seed.js')) {
  await waitForDb(); await migrate();
  await seedDemo({ reset: process.argv.includes('--reset') });
  console.log('Database di demo popolato.');
  await pool.end();
}
