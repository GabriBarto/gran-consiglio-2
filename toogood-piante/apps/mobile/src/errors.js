// Testi italiani per i codici di errore dell'API (i messaggi di Laravel sono in inglese).
const BY_CODE = {
  INVALID_CREDENTIALS: 'Email o password non corrette.',
  EMAIL_NOT_VERIFIED: 'Devi prima confermare la tua email: controlla la posta.',
  ACCOUNT_SUSPENDED: 'Questo account è sospeso.',
  ACCOUNT_NOT_ACTIVE: 'Questo account non è attivo.',
  INVALID_CODE: 'Codice non corretto.',
  CODE_EXPIRED: 'Il codice è scaduto: torna indietro e accedi di nuovo.',
  TOO_MANY_ATTEMPTS: 'Troppi tentativi: accedi di nuovo per ricevere un nuovo codice.',
  INVALID_REFRESH_TOKEN: 'Sessione scaduta: accedi di nuovo.',
  REFRESH_TOKEN_REUSED: 'Sessione non valida: accedi di nuovo.',
  INVALID_RESET_CODE: 'Codice non valido o scaduto.',
  INVALID_CURRENT_PASSWORD: 'La password attuale non è corretta.',
  NETWORK_ERROR: 'Impossibile contattare il server. Controlla la connessione.',
  RATE_LIMITED: 'Troppe richieste: riprova tra un minuto.',
};

const BY_FIELD = {
  email: 'Controlla l’indirizzo email.',
  username: 'Nome utente non valido o già in uso (3–40 caratteri).',
  password: 'La password deve avere almeno 10 caratteri, con lettere e numeri.',
  password_confirmation: 'Le due password non coincidono.',
  accept_terms: 'Devi accettare i termini per continuare.',
  code: 'Inserisci il codice di 6 cifre.',
  current_password: 'La password attuale non è corretta.',
};

export function errorMessage(err) {
  if (!err) return '';
  if (BY_CODE[err.code]) return BY_CODE[err.code];
  if (err.status === 422 && err.fields) {
    const messages = Object.keys(err.fields).map((f) => BY_FIELD[f]).filter(Boolean);
    if (messages.length) return [...new Set(messages)].join('\n');
  }
  return 'Qualcosa è andato storto. Riprova.';
}
