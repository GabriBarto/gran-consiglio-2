import * as SecureStore from 'expo-secure-store';

const KEY = 'toogood.refresh_token';

export const loadRefreshToken = () => SecureStore.getItemAsync(KEY).catch(() => null);
export const saveRefreshToken = (token) => SecureStore.setItemAsync(KEY, token);
export const clearRefreshToken = () => SecureStore.deleteItemAsync(KEY).catch(() => {});

// Token del "dispositivo fidato": serve a non chiedere un nuovo codice a ogni accesso.
// Resta salvato anche dopo l'uscita; l'API lo annulla da sola se cambia la password.
const DEVICE_KEY = 'toogood.device_token';

export const loadDeviceToken = () => SecureStore.getItemAsync(DEVICE_KEY).catch(() => null);
export const saveDeviceToken = (token) => SecureStore.setItemAsync(DEVICE_KEY, token);
