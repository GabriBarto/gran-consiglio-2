// Importi: il server ragiona in centesimi interi, l'interfaccia in euro.

export const formatEuro = (cents) =>
  `${(cents / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;

// "2,50", "2.5" o "2" → centesimi. Restituisce null se non è un importo valido (niente più di 2 decimali).
export function parseEuroToCents(text) {
  const cleaned = String(text ?? '').trim().replace(/\s*€$/, '').replace(',', '.');
  if (!/^\d+(\.\d{1,2})?$/.test(cleaned)) return null;
  const [whole, frac = ''] = cleaned.split('.');
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'));
}
