import { route } from '../lib/http.js';
import { seedDemo } from '../seed.js';
import { DEMO_MODE } from './public.js';

if (DEMO_MODE) route('POST', '/api/demo/reset', async () => { await seedDemo({ reset: true }); return { ok: true }; });
