import crypto from 'node:crypto';
import { promisify } from 'node:util';
import { query } from './db.js';
import { HttpError, str } from './lib/http.js';

const scrypt = promisify(crypto.scrypt);
const SESSION_DAYS = 30;

// ---------- password ----------
export async function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const hash = await scrypt(password, salt, 64);
  return `scrypt$${salt.toString('hex')}$${hash.toString('hex')}`;
}

export async function verifyPassword(password, stored) {
  const [alg, saltHex, hashHex] = String(stored).split('$');
  if (alg !== 'scrypt' || !saltHex || !hashHex) return false;
  const expected = Buffer.from(hashHex, 'hex');
  const actual = await scrypt(password, Buffer.from(saltHex, 'hex'), expected.length);
  return crypto.timingSafeEqual(actual, expected);
}

export function checkPasswordStrength(password) {
  if (password.length < 8) throw new HttpError(400, 'La password deve avere almeno 8 caratteri');
  if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) throw new HttpError(400, 'La password deve contenere lettere e numeri');
}

// ---------- sessioni ----------
const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');

export async function createSession(userId) {
  const token = crypto.randomBytes(32).toString('base64url');
  await query(`INSERT INTO sessions (token_hash, user_id, expires_at) VALUES ($1, $2, now() + make_interval(days => $3))`,
    [sha256(token), userId, SESSION_DAYS]);
  return token;
}

export const destroySession = (token) => query('DELETE FROM sessions WHERE token_hash = $1', [sha256(token)]);

export const destroyOtherSessions = (userId, token) =>
  query('DELETE FROM sessions WHERE user_id = $1 AND token_hash <> $2', [userId, sha256(token)]);

export const bearer = (req) => (req.headers.authorization || '').replace(/^Bearer\s+/i, '');

/** Restituisce l'utente della richiesta, oppure lancia 401/403. `roles` limita i ruoli ammessi. */
export async function requireUser(req, roles) {
  const token = bearer(req);
  if (!token) throw new HttpError(401, 'Accesso richiesto');
  const { rows } = await query(
    `SELECT u.id, u.username, u.email, u.role, u.status, u.must_change_password
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token_hash = $1 AND s.expires_at > now()`, [sha256(token)]);
  const user = rows[0];
  if (!user) throw new HttpError(401, 'Sessione scaduta, accedi di nuovo');
  if (user.status === 'suspended') throw new HttpError(403, 'Account sospeso');
  if (roles && !roles.includes(user.role)) throw new HttpError(403, 'Operazione non consentita per il tuo tipo di account');
  return user;
}

// ---------- limite ai tentativi di accesso ----------
const attempts = new Map(); // chiave -> { count, resetAt }
const MAX_ATTEMPTS = 10, WINDOW_MS = 15 * 60 * 1000;

export function checkLoginRate(key) {
  const now = Date.now();
  const a = attempts.get(key);
  if (a && a.resetAt > now && a.count >= MAX_ATTEMPTS) throw new HttpError(429, 'Troppi tentativi, riprova tra qualche minuto');
}
export function recordLoginFailure(key) {
  const now = Date.now();
  const a = attempts.get(key);
  if (!a || a.resetAt <= now) attempts.set(key, { count: 1, resetAt: now + WINDOW_MS });
  else a.count++;
}
export const clearLoginFailures = (key) => attempts.delete(key);

// ---------- validazione dei campi di registrazione ----------
export function parseCredentials(b) {
  const username = str(b.username, 30), email = str(b.email, 120).toLowerCase();
  if (!/^[A-Za-z0-9_.-]{3,30}$/.test(username)) throw new HttpError(400, 'Nome utente: 3-30 caratteri tra lettere, numeri, . _ -');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new HttpError(400, 'Email non valida');
  return { username, email };
}
