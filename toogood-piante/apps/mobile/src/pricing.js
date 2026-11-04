// Formula del prezzo (RB-04, RB-05), in centesimi interi. È solo l'anteprima per il venditore:
// il server ricalcola sempre. Le due implementazioni sono tenute allineate dai casi condivisi
// in contracts/price-cases.json (eseguiti da `npm test` qui e dai test PHP nel backend).

export function quote(listValueCents, discountPct, feeCents) {
  const price = Math.floor((listValueCents * (100 - discountPct) + 50) / 100);
  return { price_cents: price, fee_cents: feeCents, earning_cents: price - feeCents };
}

// "20", "20,5", "20.50" → centesimi; null se non è un importo valido (max 2 decimali).
export function eurosToCents(text) {
  const match = String(text).trim().replace(',', '.').match(/^(\d{1,5})(?:\.(\d{1,2}))?$/);
  if (!match) return null;
  return parseInt(match[1], 10) * 100 + parseInt((match[2] ?? '').padEnd(2, '0') || '0', 10);
}

export function formatEuro(cents) {
  const sign = cents < 0 ? '−' : '';
  const abs = Math.abs(cents);
  return `${sign}${Math.floor(abs / 100)},${String(abs % 100).padStart(2, '0')} €`;
}

// Distanza leggibile: 350 m, 1,2 km, 14 km (a 10 m di precisione sotto il chilometro)
export function formatDistance(meters) {
  if (meters == null) return '';
  if (meters < 1000) return `${Math.max(10, Math.round(meters / 10) * 10)} m`;
  const km = meters / 1000;
  return `${km < 10 ? km.toFixed(1).replace('.', ',') : Math.round(km)} km`;
}
