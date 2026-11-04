import * as SecureStore from 'expo-secure-store';

// La box che un visitatore stava guardando quando ha toccato "Accedi per acquistare" (US-N03).
// Si salva su disco perché tra la registrazione e il primo accesso passa la conferma dell'email,
// e nel frattempo l'app può essere chiusa. Dopo il primo accesso da cliente si torna a quella box (US-N04).
const KEY = 'toogood.pending_box';
const MAX_AGE_MS = 3 * 24 * 60 * 60 * 1000; // dopo tre giorni non ha più senso riportarcelo

export async function savePendingBox({ shopId, boxId }) {
  try {
    await SecureStore.setItemAsync(KEY, JSON.stringify({ shopId, boxId, savedAt: Date.now() }));
  } catch { /* se non si salva, si perde solo il ritorno automatico */ }
}

export async function loadPendingBox() {
  try {
    const saved = JSON.parse((await SecureStore.getItemAsync(KEY)) ?? 'null');
    if (!saved || Date.now() - saved.savedAt > MAX_AGE_MS) return null;
    return saved;
  } catch {
    return null;
  }
}

export const clearPendingBox = () => SecureStore.deleteItemAsync(KEY).catch(() => {});
