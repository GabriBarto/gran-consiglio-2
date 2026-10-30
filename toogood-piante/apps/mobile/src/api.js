import { API_URL } from './config';

export class ApiError extends Error {
  constructor(status, code, message, fields) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

// Il contesto di autenticazione registra qui come leggere e rinnovare i token.
let session = { getAccessToken: () => null, refresh: async () => false };
export const bindSession = (s) => { session = s; };

async function send(method, path, body, token) {
  let res;
  try {
    res = await fetch(`${API_URL}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Network error');
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const code = data.code ?? (res.status === 429 ? 'RATE_LIMITED' : undefined);
    throw new ApiError(res.status, code, data.message ?? 'Error', data.errors);
  }
  return data;
}

export const publicRequest = (method, path, body) => send(method, path, body);

// Richiesta autenticata: se l'access token è scaduto (401) lo rinnova una volta e riprova.
export async function request(method, path, body) {
  try {
    return await send(method, path, body, session.getAccessToken());
  } catch (err) {
    if (err.status === 401 && (await session.refresh())) {
      return send(method, path, body, session.getAccessToken());
    }
    throw err;
  }
}

export const api = {
  register: (data) => publicRequest('POST', '/auth/register', data),
  resendVerification: (email) => publicRequest('POST', '/auth/verify-email/resend', { email }),
  login: (email, password) => publicRequest('POST', '/auth/login', { email, password }),
  verifyLogin: (challenge_id, code, device) =>
    publicRequest('POST', '/auth/login/verify', { challenge_id, code, device }),
  refresh: (refresh_token) => publicRequest('POST', '/auth/refresh', { refresh_token }),
  forgotPassword: (email) => publicRequest('POST', '/auth/password/forgot', { email }),
  resetPassword: (data) => publicRequest('POST', '/auth/password/reset', data),
  me: () => request('GET', '/me'),
  logout: (refresh_token) => request('POST', '/auth/logout', { refresh_token }),
  changePassword: (data) => request('POST', '/auth/password/change', data),
};
