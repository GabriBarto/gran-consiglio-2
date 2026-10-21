const R = 6371; // raggio medio della Terra in km
const rad = (d) => (d * Math.PI) / 180;

/** Distanza in km tra due punti (formula di Haversine), arrotondata a 0,1 km. */
export function distanceKm(lat1, lng1, lat2, lng2) {
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
  return Math.round(2 * R * Math.asin(Math.sqrt(a)) * 10) / 10;
}

/** Legge ?lat=&lng= dalla richiesta: restituisce { lat, lng } oppure null se assenti o fuori range. */
export function positionFrom(url) {
  const lat = Number(url.searchParams.get('lat')), lng = Number(url.searchParams.get('lng'));
  if (!url.searchParams.get('lat') || !url.searchParams.get('lng')) return null;
  return Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? { lat, lng } : null;
}

/** Aggiunge `distance_km` (null se mancano le coordinate) e ordina dal più vicino. */
export function withDistance(rows, pos, { latKey = 'shop_lat', lngKey = 'shop_lng' } = {}) {
  if (!pos) return rows;
  const out = rows.map((r) => ({ ...r, distance_km: r[latKey] == null ? null : distanceKm(pos.lat, pos.lng, r[latKey], r[lngKey]) }));
  return out.sort((a, b) => (a.distance_km ?? Infinity) - (b.distance_km ?? Infinity));
}
