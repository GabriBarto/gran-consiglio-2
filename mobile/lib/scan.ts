// Passaggio del codice scansionato dalla schermata scanner alla dashboard venditore.
let pending: string | null = null;
export const setScannedCode = (c: string) => { pending = c; };
export const takeScannedCode = () => { const c = pending; pending = null; return c; };
