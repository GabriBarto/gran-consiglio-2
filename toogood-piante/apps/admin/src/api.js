export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8080/api';

export class ApiError extends Error {
  constructor(status, code, message, fields) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

// L'AuthProvider registra qui come leggere e rinnovare i token.
let session = { getAccessToken: () => null, refresh: async () => false };
export const bindSession = (s) => { session = s; };

async function send(method, path, body, token, raw = false) {
  let res;
  try {
    res = await fetch(`${API_URL}${path}`, {
      method,
      headers: {
        Accept: raw ? '*/*' : 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Network error');
  }
  if (res.status === 204) return null;
  if (res.ok && raw) return res.blob();
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const code = data.code ?? (res.status === 429 ? 'RATE_LIMITED' : undefined);
    throw new ApiError(res.status, code, data.message ?? 'Error', data.errors);
  }
  return data;
}

const publicRequest = (method, path, body) => send(method, path, body);

// Richiesta autenticata: su 401 rinnova il token una volta e riprova.
async function request(method, path, body, raw = false) {
  try {
    return await send(method, path, body, session.getAccessToken(), raw);
  } catch (err) {
    if (err.status === 401 && (await session.refresh())) {
      return send(method, path, body, session.getAccessToken(), raw);
    }
    throw err;
  }
}

export const api = {
  login: (email, password) => publicRequest('POST', '/auth/login', { email, password }),
  verifyLogin: (challenge_id, code) =>
    publicRequest('POST', '/auth/login/verify', { challenge_id, code, device: 'admin-web' }),
  refresh: (refresh_token) => publicRequest('POST', '/auth/refresh', { refresh_token }),
  me: () => request('GET', '/me'),
  logout: (refresh_token) => request('POST', '/auth/logout', { refresh_token }),
  changePassword: (data) => request('POST', '/auth/password/change', data),

  sellers: (status) => request('GET', `/admin/sellers?status=${status}`),
  seller: (id) => request('GET', `/admin/sellers/${id}`),
  // Il documento passa dall'API (serve il token), quindi si scarica come file e si apre in locale.
  sellerDocument: (id, docId) => request('GET', `/admin/sellers/${id}/documents/${docId}`, undefined, true),
  approve: (id) => request('POST', `/admin/sellers/${id}/approve`),
  reject: (id, note) => request('POST', `/admin/sellers/${id}/reject`, { note }),
  // Sospensione e riattivazione (giorno 13)
  suspend: (id, note) => request('POST', `/admin/sellers/${id}/suspend`, { note }),
  reactivate: (id) => request('POST', `/admin/sellers/${id}/reactivate`),

  // Reclami e rimborsi (giorno 12): status è OPEN (da decidere) o DECIDED
  complaints: (status) => request('GET', `/admin/complaints?status=${status}`),
  complaint: (id) => request('GET', `/admin/complaints/${id}`),
  decide: (id, body) => request('POST', `/admin/complaints/${id}/decide`, body),
};
