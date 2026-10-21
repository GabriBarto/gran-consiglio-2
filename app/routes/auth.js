import { query } from '../db.js';
import {
  bearer, checkLoginRate, checkPasswordStrength, clearLoginFailures, createSession, destroyOtherSessions, destroySession,
  hashPassword, parseCredentials, recordLoginFailure, requireUser, verifyPassword,
} from '../auth.js';
import { HttpError, readJson, route, str } from '../lib/http.js';

const publicUser = (u) => ({ id: u.id, username: u.username, email: u.email, role: u.role, must_change_password: u.must_change_password });

export const shopOf = async (userId) =>
  (await query('SELECT id, name, emoji, city, license_status FROM shops WHERE owner_id = $1 ORDER BY id LIMIT 1', [userId])).rows[0] ?? null;

route('POST', '/api/auth/register', async ({ req }) => {
  const b = await readJson(req);
  const { username, email } = parseCredentials(b);
  const role = str(b.role, 20);
  if (!['cliente', 'venditore'].includes(role)) throw new HttpError(400, 'Scegli se sei un cliente o un venditore');
  const password = String(b.password ?? '');
  checkPasswordStrength(password);
  if (password !== String(b.password_confirm ?? '')) throw new HttpError(400, 'Le due password non coincidono');

  const dup = await query('SELECT username, email FROM users WHERE lower(username) = lower($1) OR email = $2', [username, email]);
  if (dup.rows.length) throw new HttpError(409, dup.rows[0].email === email ? 'Esiste già un account con questa email' : 'Nome utente già in uso');

  const { rows } = await query(
    `INSERT INTO users (username, email, password_hash, role) VALUES ($1, $2, $3, $4)
     RETURNING id, username, email, role, must_change_password`, [username, email, await hashPassword(password), role]);
  const user = rows[0];
  return { token: await createSession(user.id), user: publicUser(user), shop: null };
});

route('POST', '/api/auth/login', async ({ req }) => {
  const b = await readJson(req);
  const email = str(b.email, 120).toLowerCase();
  const key = `${req.socket.remoteAddress}|${email}`;
  checkLoginRate(key);
  const { rows } = await query('SELECT * FROM users WHERE email = $1', [email]);
  const user = rows[0];
  // verifica anche per email inesistenti, per non rivelare quali account esistono
  const ok = user ? await verifyPassword(String(b.password ?? ''), user.password_hash) : await verifyPassword('x', 'scrypt$00$00');
  if (!user || !ok) { recordLoginFailure(key); throw new HttpError(401, 'Email o password non corretti'); }
  if (user.status === 'suspended') throw new HttpError(403, 'Account sospeso');
  clearLoginFailures(key);
  return { token: await createSession(user.id), user: publicUser(user), shop: user.role === 'venditore' ? await shopOf(user.id) : null };
});

route('POST', '/api/auth/logout', async ({ req }) => {
  const token = bearer(req);
  if (token) await destroySession(token);
  return { ok: true };
});

route('GET', '/api/auth/me', async ({ req }) => {
  const user = await requireUser(req);
  return { user: publicUser(user), shop: user.role === 'venditore' ? await shopOf(user.id) : null };
});

route('POST', '/api/auth/change-password', async ({ req }) => {
  const user = await requireUser(req);
  const b = await readJson(req);
  const { rows } = await query('SELECT password_hash FROM users WHERE id = $1', [user.id]);
  if (!(await verifyPassword(String(b.current_password ?? ''), rows[0].password_hash))) throw new HttpError(401, 'Password attuale non corretta');
  const next = String(b.new_password ?? '');
  checkPasswordStrength(next);
  if (next !== String(b.new_password_confirm ?? '')) throw new HttpError(400, 'Le due password non coincidono');
  await query('UPDATE users SET password_hash = $1, must_change_password = FALSE WHERE id = $2', [await hashPassword(next), user.id]);
  // chiude le altre sessioni, tiene quella corrente
  await destroyOtherSessions(user.id, bearer(req));
  return { ok: true };
});
