import * as SecureStore from 'expo-secure-store';

const KEY = 'toogood.refresh_token';

export const loadRefreshToken = () => SecureStore.getItemAsync(KEY).catch(() => null);
export const saveRefreshToken = (token) => SecureStore.setItemAsync(KEY, token);
export const clearRefreshToken = () => SecureStore.deleteItemAsync(KEY).catch(() => {});

// Token del "dispositivo fidato": serve a non chiedere un nuovo codice a ogni accesso.
// Ce n'è uno per ogni account (cliente e venditore sullo stesso telefono hanno token diversi),
// in un'unica voce del deposito sicuro: {"email": "token"}. Resta salvato anche dopo l'uscita;
// l'API lo annulla da sola se cambia la password.
const DEVICE_KEY = 'toogood.device_tokens';

const normalize = (email) => String(email).trim().toLowerCase();

async function readDeviceTokens() {
  try {
    return JSON.parse((await SecureStore.getItemAsync(DEVICE_KEY)) ?? '{}');
  } catch {
    return {};
  }
}

export async function loadDeviceToken(email) {
  return (await readDeviceTokens())[normalize(email)] ?? null;
}

export async function saveDeviceToken(email, token) {
  const tokens = await readDeviceTokens();
  tokens[normalize(email)] = token;
  await SecureStore.setItemAsync(DEVICE_KEY, JSON.stringify(tokens));
}
