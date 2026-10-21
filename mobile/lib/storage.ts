import AsyncStorage from '@react-native-async-storage/async-storage';
import type { SellerSession } from './api';

const CODES = 'vs-codes';
const SELLER = 'vs-seller';

export async function getCodes(): Promise<string[]> {
  try { return JSON.parse((await AsyncStorage.getItem(CODES)) ?? '[]'); } catch { return []; }
}
export async function addCode(code: string) {
  await AsyncStorage.setItem(CODES, JSON.stringify([code, ...(await getCodes())]));
}
export async function getSeller(): Promise<SellerSession | null> {
  try { return JSON.parse((await AsyncStorage.getItem(SELLER)) ?? 'null'); } catch { return null; }
}
export async function setSeller(s: SellerSession | null) {
  if (s) await AsyncStorage.setItem(SELLER, JSON.stringify(s));
  else await AsyncStorage.removeItem(SELLER);
}
