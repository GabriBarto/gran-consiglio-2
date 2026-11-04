// Testi italiani per i codici di errore dell'API (i messaggi di Laravel sono in inglese).
const BY_CODE = {
  INVALID_CREDENTIALS: 'Email o password non corrette.',
  EMAIL_NOT_VERIFIED: 'Email non ancora confermata.',
  ACCOUNT_SUSPENDED: 'Questo account è sospeso.',
  ACCOUNT_NOT_ACTIVE: 'Questo account non è attivo.',
  INVALID_CODE: 'Codice non corretto.',
  CODE_EXPIRED: 'Il codice è scaduto: torna indietro e accedi di nuovo.',
  TOO_MANY_ATTEMPTS: 'Troppi tentativi: accedi di nuovo per ricevere un nuovo codice.',
  INVALID_CURRENT_PASSWORD: 'La password attuale non è corretta.',
  NETWORK_ERROR: 'Impossibile contattare il server. È acceso lo stack Docker?',
  RATE_LIMITED: 'Troppe richieste: riprova tra un minuto.',
  FORBIDDEN_ROLE: 'Questo pannello è riservato agli amministratori.',
  NO_DOCUMENTS: 'Il venditore non ha allegato documenti: non si può approvare.',
  INVALID_STATE: 'La richiesta è già stata decisa. Aggiorna la lista.',
  NOT_FOUND: 'Elemento non trovato.',
};

const BY_FIELD = {
  email: 'Controlla l’indirizzo email.',
  password: 'La password deve avere almeno 10 caratteri, con lettere e numeri.',
  password_confirmation: 'Le due password non coincidono.',
  code: 'Inserisci il codice di 6 cifre.',
  current_password: 'La password attuale non è corretta.',
  note: 'Il motivo del rifiuto deve avere tra 5 e 1000 caratteri.',
};

export function errorMessage(err) {
  if (!err) return '';
  if (BY_CODE[err.code]) return BY_CODE[err.code];
  if (err.status === 422 && err.fields) {
    const messages = Object.keys(err.fields).map((f) => BY_FIELD[f]).filter(Boolean);
    if (messages.length) return [...new Set(messages)].join(' ');
  }
  return 'Qualcosa è andato storto. Riprova.';
}
