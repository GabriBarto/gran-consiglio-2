export const eur = (cents: number) =>
  (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });

export const hhmm = (iso: string) =>
  new Date(iso).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

export const day = (iso: string) =>
  new Date(iso).toDateString() === new Date().toDateString()
    ? 'Oggi'
    : new Date(iso).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' });

export const slot = (start: string, end: string) => `${day(start)} ${hhmm(start)}–${hhmm(end)}`;
