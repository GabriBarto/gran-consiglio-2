import Constants from 'expo-constants';
import { Platform } from 'react-native';

export type Offer = {
  id: number; shop_id: number; title: string; description: string; category: string; emoji: string;
  original_price_cents: number; price_cents: number; quantity: number; reserved: number; available: number;
  pickup_start: string; pickup_end: string;
  shop_name: string; shop_type: string; city: string; address: string; shop_emoji: string;
};
export type Reservation = {
  code: string; name: string; qty: number; status: 'reserved' | 'collected'; title: string; emoji: string;
  price_cents: number; pickup_start: string; pickup_end: string; shop_name: string; address: string; city: string;
};
export type Stats = { plantsSaved: number; shops: number; moneySavedCents: number; sellerRevenueCents: number };
export type Shop = { id: number; name: string; type: string; city: string; emoji: string };
export type SellerSession = { token: string; shop: Omit<Shop, 'type'> };
export type Dashboard = {
  offers: Offer[]; plantsSaved: number; revenueCents: number;
  reservations: { code: string; name: string; qty: number; status: string; title: string }[];
};

/**
 * Indirizzo del backend. Ordine di priorità:
 * 1. EXPO_PUBLIC_API_URL (es. http://192.168.1.20:8080)
 * 2. lo stesso PC che sta servendo Expo (telefono e PC sulla stessa rete Wi-Fi)
 */
function baseUrl(): string {
  if (process.env.EXPO_PUBLIC_API_URL) return process.env.EXPO_PUBLIC_API_URL.replace(/\/$/, '');
  if (Platform.OS === 'web' && typeof location !== 'undefined') return `http://${location.hostname}:8080`;
  const host = Constants.expoConfig?.hostUri?.split(':')[0];
  return `http://${host ?? 'localhost'}:8080`;
}
export const API_URL = baseUrl();

export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

export async function api<T>(path: string, opts: { method?: string; body?: unknown; token?: string } = {}): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`${API_URL}${path}`, {
      method: opts.method ?? 'GET',
      headers: { 'Content-Type': 'application/json', ...(opts.token ? { Authorization: `Bearer ${opts.token}` } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
  } catch {
    throw new ApiError(0, `Server non raggiungibile (${API_URL}). Il backend è avviato?`);
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.error || 'Errore');
  return data as T;
}
