import { DatabaseSync } from 'node:sqlite';
import fs from 'node:fs';
import path from 'node:path';

const file = process.env.DB_PATH || './data/verdesalva.db';
fs.mkdirSync(path.dirname(file), { recursive: true });

export const db = new DatabaseSync(file);
db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');

db.exec(`
CREATE TABLE IF NOT EXISTS shops (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL,            -- vivaio | fiorista | garden
  city TEXT NOT NULL,
  address TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  emoji TEXT NOT NULL DEFAULT '🌿',
  pin TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS offers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  shop_id INTEGER NOT NULL REFERENCES shops(id),
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL,        -- piante | fiori | erbe | piante-da-frutto | succulente
  emoji TEXT NOT NULL DEFAULT '🪴',
  original_price_cents INTEGER NOT NULL,
  price_cents INTEGER NOT NULL,
  quantity INTEGER NOT NULL,
  reserved INTEGER NOT NULL DEFAULT 0,
  pickup_start TEXT NOT NULL,
  pickup_end TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  offer_id INTEGER NOT NULL REFERENCES offers(id),
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  qty INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'reserved',  -- reserved | collected
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`);

/** Esegue fn dentro una transazione. */
export function tx(fn) {
  db.exec('BEGIN IMMEDIATE');
  try {
    const r = fn();
    db.exec('COMMIT');
    return r;
  } catch (e) {
    db.exec('ROLLBACK');
    throw e;
  }
}
