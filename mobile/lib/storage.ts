import AsyncStorage from '@react-native-async-storage/async-storage';
import type { Session } from './api';

const CODES = 'vs-codes';
const SESSION = 'vs-session';

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
