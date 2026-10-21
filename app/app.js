import http from 'node:http';
import './routes/public.js';
import './routes/auth.js';
import './routes/customer.js';
import './routes/seller.js';
import './routes/demo.js';
import { CORS, HttpError, routes, send, serveStatic } from './lib/http.js';

export const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (req.method === 'OPTIONS') { res.writeHead(204, CORS); return res.end(); }
  try {
    for (const r of routes) {
      const match = r.method === req.method && r.re.exec(url.pathname);
      if (match) return send(res, 200, await r.handler({ req, url, match: match.slice(1) }));
    }
    if (url.pathname.startsWith('/api/')) throw new HttpError(404, 'Endpoint non trovato');
    if (req.method !== 'GET') throw new HttpError(405, 'Metodo non consentito');
    serveStatic(res, url.pathname);
  } catch (e) {
    if (!(e instanceof HttpError)) console.error(e);
    send(res, e.status || 500, { error: e.status ? e.message : 'Errore interno', ...(e.code ? { code: e.code } : {}) });
  }
});
