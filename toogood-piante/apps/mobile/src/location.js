import * as Location from 'expo-location';

// Ultima posizione nota: la scheda di un negozio la riusa per mostrare la distanza senza richiederla di nuovo.
let lastPosition = null; // { lat, lng }

export const getLastPosition = () => lastPosition;

/**
 * Chiede il permesso di geolocalizzazione e, se concesso, la posizione attuale.
 * Esiti: 'granted' (con la posizione), 'denied' (permesso negato), 'unavailable' (permesso dato ma niente posizione).
 */
export async function requestPosition() {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') return { state: 'denied', position: null };
  } catch {
    return { state: 'unavailable', position: null };
  }

  try {
    const { coords } = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
    lastPosition = { lat: coords.latitude, lng: coords.longitude };
    return { state: 'granted', position: lastPosition };
  } catch {
    // Posizione spenta o non ancora disponibile (succede spesso negli emulatori)
    return { state: 'unavailable', position: null };
  }
}
