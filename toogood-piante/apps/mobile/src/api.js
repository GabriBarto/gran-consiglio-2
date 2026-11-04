import { File } from 'expo-file-system';
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
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
  let res;
  try {
    res = await fetch(`${API_URL}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        // Per i file (FormData) il Content-Type lo imposta fetch, con il separatore giusto
        ...(isForm ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });
  } catch (err) {
    // In sviluppo il motivo vero compare nel terminale di Expo
    if (__DEV__) console.warn(`Richiesta fallita: ${method} ${path} –`, err?.message ?? err);
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

// ?a=1&b=2 senza i valori vuoti (URLSearchParams in React Native è incompleto: si costruisce a mano)
const query = (params = {}) => {
  const pairs = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
  return pairs.length ? `?${pairs.join('&')}` : '';
};

export const api = {
  register: (data) => publicRequest('POST', '/auth/register', data),
  resendVerification: (email) => publicRequest('POST', '/auth/verify-email/resend', { email }),
  login: (email, password, device_token, device) =>
    publicRequest('POST', '/auth/login', { email, password, device_token: device_token ?? undefined, device }),
  verifyLogin: (challenge_id, code, device) =>
    publicRequest('POST', '/auth/login/verify', { challenge_id, code, device, remember_device: true }),
  refresh: (refresh_token) => publicRequest('POST', '/auth/refresh', { refresh_token }),
  forgotPassword: (email) => publicRequest('POST', '/auth/password/forgot', { email }),
  resetPassword: (data) => publicRequest('POST', '/auth/password/reset', data),
  me: () => request('GET', '/me'),
  logout: (refresh_token) => request('POST', '/auth/logout', { refresh_token }),
  changePassword: (data) => request('POST', '/auth/password/change', data),

  // Onboarding del venditore
  sellerStatus: () => request('GET', '/seller'),
  saveSellerProfile: (data) => request('PUT', '/seller/profile', data),
  // file: { uri, name, mimeType } scelto con il selettore di documenti.
  // Il fetch di Expo SDK 58 non accetta più la forma { uri, name, type }: vuole una parte con i byte del file.
  uploadSellerDocument: (file) => {
    const local = new File(file.uri);
    const form = new FormData();
    form.append('file', { name: file.name, type: file.mimeType ?? 'application/octet-stream', bytes: () => local.bytes() });
    return request('POST', '/seller/documents', form);
  },
  deleteSellerDocument: (id) => request('DELETE', `/seller/documents/${id}`),
  resubmitSeller: () => request('POST', '/seller/resubmit'),
  stripeOnboardingUrl: () => request('POST', '/seller/stripe/onboarding'),
  stripeSync: () => request('POST', '/seller/stripe/sync'),

  // Ricerca e scheda dei negozi (pubbliche: funzionano anche senza accesso)
  shops: (params) => publicRequest('GET', `/shops${query(params)}`),
  shop: (id, position) => publicRequest('GET', `/shops/${id}${query(position)}`),

  // Box del giorno
  boxOptions: () => request('GET', '/seller/box-options'),
  boxes: () => request('GET', '/seller/boxes'),
  box: (id) => request('GET', `/seller/boxes/${id}`),
  createBox: (data) => request('POST', '/seller/boxes', data),
  updateBox: (id, data) => request('PUT', `/seller/boxes/${id}`, data),
  deleteBox: (id) => request('DELETE', `/seller/boxes/${id}`),
};
