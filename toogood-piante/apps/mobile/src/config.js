import Constants from 'expo-constants';
import { Platform } from 'react-native';

// Indirizzo dell'API. In ordine: variabile EXPO_PUBLIC_API_URL, poi l'IP del PC che serve Expo
// (funziona con Expo Go su telefono vero), poi l'alias dell'emulatore Android, infine localhost.
function defaultApiUrl() {
  if (process.env.EXPO_PUBLIC_API_URL) return process.env.EXPO_PUBLIC_API_URL;
  const hostUri = Constants.expoConfig?.hostUri;
  if (hostUri) return `http://${hostUri.split(':')[0]}:8080/api`;
  if (Platform.OS === 'android') return 'http://10.0.2.2:8080/api';
  return 'http://localhost:8080/api';
}

export const API_URL = defaultApiUrl();
