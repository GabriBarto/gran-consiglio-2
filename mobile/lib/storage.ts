import AsyncStorage from '@react-native-async-storage/async-storage';
import type { Session } from './api';

const CODES = 'vs-codes';
const SESSION = 'vs-session';
const NEXT = 'vs-next';

export async function getCodes(): Promise<string[]> {
  try { return JSON.parse((await AsyncStorage.getItem(CODES)) ?? '[]'); } catch { return []; }
}
export async function addCode(code: string) {
  await AsyncStorage.setItem(CODES, JSON.stringify([code, ...(await getCodes())]));
}
export async function getSession(): Promise<Session | null> {
  try { return JSON.parse((await AsyncStorage.getItem(SESSION)) ?? 'null'); } catch { return null; }
}
export async function setSession(s: Session | null) {
  if (s) await AsyncStorage.setItem(SESSION, JSON.stringify(s));
  else await AsyncStorage.removeItem(SESSION);
}

/** Schermata a cui tornare dopo registrazione/accesso (es. la box che il cliente stava guardando). */
export const setNext = (path: string | null) => (path ? AsyncStorage.setItem(NEXT, path) : AsyncStorage.removeItem(NEXT));
export async function takeNext(): Promise<string | null> {
  const v = await AsyncStorage.getItem(NEXT);
  await AsyncStorage.removeItem(NEXT);
  return v;
}
