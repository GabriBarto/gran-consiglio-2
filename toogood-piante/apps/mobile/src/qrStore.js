import * as SecureStore from 'expo-secure-store';

// Il biglietto di ritiro si salva sul telefono appena caricato, così si mostra al negozio anche con poca
// o nessuna connessione (US-C09 CA3). Una voce per ordine: sono poche centinaia di caratteri.
const key = (orderId) => `toogood.qr.${orderId}`;

export async function saveTicket(orderId, ticket) {
  try {
    await SecureStore.setItemAsync(key(orderId), JSON.stringify(ticket));
  } catch { /* se non si salva, si perde solo la copia per quando manca la rete */ }
}

export async function loadTicket(orderId) {
  try {
    return JSON.parse((await SecureStore.getItemAsync(key(orderId))) ?? 'null');
  } catch {
    return null;
  }
}
