import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';

const dir = path.dirname(fileURLToPath(import.meta.url));

export const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL || 'postgres://verdesalva:verdesalva@localhost:5432/verdesalva',
});

export const query = (text, params) => pool.query(text, params);

/** Esegue fn(client) in una transazione. */
export async function tx(fn) {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  } finally {
    client.release();
  }
}

/** Attende che il database accetti connessioni (all'avvio di Docker Compose può servire qualche secondo). */
export async function waitForDb(retries = 20) {
  for (let i = 1; ; i++) {
    try { await pool.query('SELECT 1'); return; }
    catch (e) {
      if (i >= retries) throw e;
      console.log(`Database non pronto (${i}/${retries}), riprovo…`);
      await new Promise((r) => setTimeout(r, 1500));
    }
  }
}

/** Applica in ordine i file migrations/*.sql non ancora eseguiti. */
export async function migrate() {
  const files = fs.readdirSync(path.join(dir, 'migrations')).filter((f) => f.endsWith('.sql')).sort();
  const client = await pool.connect();
  try {
    await client.query('SELECT pg_advisory_lock(727274)');   // evita corse tra più istanze
    await client.query('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    const done = new Set((await client.query('SELECT name FROM schema_migrations')).rows.map((r) => r.name));
    for (const f of files) {
      if (done.has(f)) continue;
      await client.query('BEGIN');
      try {
        await client.query(fs.readFileSync(path.join(dir, 'migrations', f), 'utf8'));
        await client.query('INSERT INTO schema_migrations (name) VALUES ($1)', [f]);
        await client.query('COMMIT');
        console.log(`Migrazione applicata: ${f}`);
      } catch (e) { await client.query('ROLLBACK'); throw e; }
    }
  } finally {
    await client.query('SELECT pg_advisory_unlock(727274)').catch(() => {});
    client.release();
  }
}
