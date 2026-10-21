import { db, tx } from './db.js';

const H = 3600 * 1000;
const iso = (ms) => new Date(Date.now() + ms).toISOString();

const shops = [
  ['Vivaio Il Glicine', 'vivaio', 'Firenze', 'Via delle Cascine 12', 'Vivaio a conduzione familiare dal 1974. Piante da giardino e da terrazzo.', '🌳', '1111'],
  ['Fiori & Fiori di Marta', 'fiorista', 'Firenze', 'Via dei Servi 48', 'Bouquet e composizioni fresche ogni mattina.', '💐', '2222'],
  ['Garden Verde Pistoia', 'garden', 'Pistoia', 'Viale Adua 210', 'Garden center con serre, attrezzi e tanto verde.', '🏡', '3333'],
  ['Succulente Matte', 'vivaio', 'Prato', 'Via Pistoiese 7', 'Specialisti di cactus e succulente da collezione.', '🌵', '4444'],
  ['Il Bosso e la Rosa', 'fiorista', 'Prato', 'Piazza Mercatale 3', 'Fiorista di quartiere, piante da interno e fiori recisi.', '🌹', '5555'],
];

// [shop, titolo, descrizione, categoria, emoji, prezzo pieno €, prezzo salvataggio €, quantità, inizio ritiro (h), fine ritiro (h)]
const offers = [
  [1, 'Sorpresa Aromatiche', 'Mix di 5 vasi di erbe aromatiche (basilico, rosmarino, salvia, menta, timo) a fine stagione ma ancora rigogliose.', 'erbe', '🌿', 25, 8.9, 6, 1, 6],
  [1, 'Lavanda & Rosmarino da giardino', 'Piante in vaso 18cm, leggermente sfiorite. Perfette per bordure.', 'piante', '💜', 30, 11.0, 4, 2, 8],
  [1, 'Agrumi in vaso (limone o mandarino)', 'Piccoli alberelli con qualche foglia da sistemare. Sopravvivono benissimo con un po\' di cure.', 'piante-da-frutto', '🍋', 45, 19.0, 3, 1, 5],
  [2, 'Bouquet del giorno prima', 'Fiori recisi freschi invenduti di ieri: ancora 7-10 giorni di vita in vaso.', 'fiori', '💐', 28, 9.0, 8, 0.5, 4],
  [2, 'Sorpresa Orchidea', 'Phalaenopsis con fiori aperti, vaso leggermente segnato.', 'piante', '🌸', 35, 14.0, 3, 1, 7],
  [3, 'Box Piante da interno', '3 piante verdi da interno assortite (pothos, sansevieria, zamioculcas), fine serie.', 'piante', '🪴', 48, 18.0, 5, 2, 9],
  [3, 'Cassetta Orto Autunnale', 'Piantine da orto pronte al trapianto: cavolo nero, bietola, lattuga, finocchio.', 'erbe', '🥬', 18, 6.5, 10, 1, 10],
  [4, 'Mix Succulente Mini', '8 mini succulente in vasetti da 6cm, perfette per un regalo.', 'succulente', '🌵', 32, 12.0, 7, 3, 11],
  [4, 'Cactus Grande Sorpresa', 'Un cactus adulto a sorpresa: lievemente scheggiato ma sano.', 'succulente', '🏜️', 40, 15.0, 2, 3, 10],
  [5, 'Ciclamini e Primule', 'Colore per il balcone: 6 piante da fiore stagionali.', 'fiori', '🌺', 24, 8.0, 5, 0.5, 3],
  [5, 'Kokedama Sorpresa', 'Piantina avvolta nel muschio, già sospesa e pronta all\'uso.', 'piante', '🎍', 29, 10.5, 4, 1, 6],
];

export function seed({ reset = false } = {}) {
  const count = db.prepare('SELECT COUNT(*) AS c FROM shops').get().c;
  if (count > 0 && !reset) return false;
  tx(() => {
    if (reset) db.exec('DELETE FROM reservations; DELETE FROM offers; DELETE FROM shops; DELETE FROM sqlite_sequence;');
    const s = db.prepare('INSERT INTO shops (name,type,city,address,description,emoji,pin) VALUES (?,?,?,?,?,?,?)');
    shops.forEach((x) => s.run(...x));
    const o = db.prepare(`INSERT INTO offers
      (shop_id,title,description,category,emoji,original_price_cents,price_cents,quantity,pickup_start,pickup_end)
      VALUES (?,?,?,?,?,?,?,?,?,?)`);
    for (const [shop, t, d, c, e, full, price, q, from, to] of offers) {
      o.run(shop, t, d, c, e, Math.round(full * 100), Math.round(price * 100), q, iso(from * H), iso(to * H));
    }
    // qualche ritiro già completato, per rendere vive le statistiche
    const past = db.prepare(`INSERT INTO reservations (offer_id,code,name,email,qty,status) VALUES (?,?,?,?,?, 'collected')`);
    [[1, 'VS-DEMO1', 'Giulia R.', 'giulia@example.com', 2], [4, 'VS-DEMO2', 'Luca M.', 'luca@example.com', 3], [6, 'VS-DEMO3', 'Sara T.', 'sara@example.com', 1]]
      .forEach((r) => past.run(...r));
    db.exec(`UPDATE offers SET reserved = reserved + 2 WHERE id = 1;
             UPDATE offers SET reserved = reserved + 3 WHERE id = 4;
             UPDATE offers SET reserved = reserved + 1 WHERE id = 6;`);
  });
  return true;
}

if (process.argv[1] && process.argv[1].endsWith('seed.js')) {
  seed({ reset: process.argv.includes('--reset') });
  console.log('Database di demo popolato.');
}
