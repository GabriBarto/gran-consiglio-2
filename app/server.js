import { pool, migrate, waitForDb } from './db.js';
import { ensureAdmin, seedDemo } from './seed.js';
import { server } from './app.js';
import { DEMO_MODE } from './routes/public.js';

const PORT = Number(process.env.PORT || 3000);

await waitForDb();
await migrate();
await ensureAdmin();
if (DEMO_MODE && (await seedDemo({ reset: process.env.RESET_DEMO === 'true' }))) console.log('Dati di demo caricati.');

server.listen(PORT, '0.0.0.0', () => console.log(`VerdeSalva in ascolto su http://localhost:${PORT}`));
for (const sig of ['SIGTERM', 'SIGINT']) process.on(sig, () => server.close(async () => { await pool.end(); process.exit(0); }));
