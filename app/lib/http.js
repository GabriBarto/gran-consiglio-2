import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}

const PUBLIC_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'public');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };

// CORS aperto: serve all'app mobile (Expo) e alla versione web di sviluppo
export const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, Authorization',
};

export function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...CORS });
  res.end(JSON.stringify(body));
}

export async function readJson(req) {
  let raw = '';
  for await (const chunk of req) {
    raw += chunk;
    if (raw.length > 1e5) throw new HttpError(413, 'Richiesta troppo grande');
  }
  try { return raw ? JSON.parse(raw) : {}; } catch { throw new HttpError(400, 'JSON non valido'); }
}

export const routes = [];
export const route = (method, pattern, handler) => routes.push({ method, re: new RegExp(`^${pattern}$`), handler });

export function serveStatic(res, pathname) {
  const rel = pathname === '/' ? 'index.html' : pathname.slice(1);
  const file = path.normalize(path.join(PUBLIC_DIR, rel));
  if (!file.startsWith(PUBLIC_DIR) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    return send(res, 404, { error: 'Non trovato' });
  }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
}

// ---------- validazione ----------
export const str = (v, max = 200) => String(v ?? '').trim().slice(0, max);
export const int = (v) => { const n = Number(v); return Number.isInteger(n) ? n : NaN; };
export const euroToCents = (v) => Math.round(Number(v) * 100);
