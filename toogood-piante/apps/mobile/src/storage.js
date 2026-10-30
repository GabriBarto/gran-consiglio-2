import * as SecureStore from 'expo-secure-store';

const KEY = 'toogood.refresh_token';

export const loadRefreshToken = () => SecureStore.getItemAsync(KEY).catch(() => null);
export const saveRefreshToken = (token) => SecureStore.setItemAsync(KEY, token);
export const clearRefreshToken = () => SecureStore.deleteItemAsync(KEY).catch(() => {});
