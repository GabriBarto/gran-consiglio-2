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
  ADDRESS_NOT_FOUND: 'Indirizzo non trovato. Scrivi via, numero e città separati da virgole (può essere un qualsiasi comune italiano).',
  GEOCODING_UNAVAILABLE: 'Non riusciamo a verificare l’indirizzo in questo momento. Riprova tra poco.',
  VAT_INVALID: 'La partita IVA non risulta valida.',
  PROFILE_LOCKED: 'I dati di un’attività approvata non si modificano da qui.',
  PROFILE_REQUIRED: 'Compila prima i dati dell’attività.',
  NO_DOCUMENTS: 'Allega almeno un documento dell’attività.',
  TOO_MANY_DOCUMENTS: 'Puoi allegare al massimo 5 documenti.',
  STRIPE_UNAVAILABLE: 'Il servizio di pagamento non risponde. Riprova tra poco.',
  INVALID_STATE: 'Questa operazione non è disponibile nello stato attuale.',
  FORBIDDEN_ROLE: 'Non hai i permessi per questa operazione.',
  SELLER_NOT_READY: 'Per gestire le box serve un’attività approvata e il conto di pagamento collegato.',
  LOW_EARNING_CONFIRMATION_REQUIRED: 'Con questo prezzo il tuo guadagno è nullo o negativo: spunta la conferma per pubblicare.',
  PRICE_LOCKED: 'Dopo la prima vendita valore e sconto non si possono più cambiare.',
  QUANTITY_CANNOT_INCREASE: 'La quantità può solo diminuire.',
  QUANTITY_BELOW_SOLD: 'Non puoi scendere sotto il numero di box già vendute.',
  BOX_NOT_EDITABLE: 'Le box si modificano solo nel giorno di vendita.',
  BOX_HAS_ORDERS: 'Ci sono ordini per questa box: non si può eliminare. Puoi ridurre la quantità a quanto già venduto.',
  NOT_FOUND: 'Non trovato: potrebbe essere stato eliminato.',
  OUT_OF_STOCK: 'Alcune box non sono più disponibili nella quantità richiesta.',
  ORDER_DEADLINE_PASSED: 'L’orario limite per ordinare da questo negozio è passato.',
  INVALID_QR: 'Codice QR non valido: non è un biglietto di TooGood Piante, oppure è stato modificato.',
  INVALID_CODE: 'Codice non riconosciuto. Controlla di averlo scritto giusto.',
  WRONG_SHOP: 'Questo ordine è di un altro negozio.',
  ALREADY_USED: 'Questo codice è già stato usato: l’ordine è già stato ritirato.',
  NOT_YET_VALID: 'Il ritiro non è ancora iniziato.',
  EXPIRED: 'La fascia di ritiro di questo ordine è finita.',
  ORDER_NOT_ACTIVE: 'Questo ordine non è più ritirabile.',
  BOX_NOT_FOUND: 'Una delle box non esiste più.',
  BOX_NOT_AVAILABLE: 'Una delle box non è più in vendita.',
  MIXED_SHOPS: 'Un ordine può contenere box di un solo negozio.',
  SHOP_NOT_AVAILABLE: 'Questo negozio non può ricevere ordini in questo momento.',
  PAYMENT_AMOUNT_TOO_LOW: 'Il totale è troppo basso per essere pagato con carta (minimo 0,50 €).',
};

const BY_FIELD = {
  email: 'Controlla l’indirizzo email.',
  username: 'Nome utente non valido o già in uso (3–40 caratteri).',
  password: 'La password deve avere almeno 10 caratteri, con lettere e numeri.',
  password_confirmation: 'Le due password non coincidono.',
  accept_terms: 'Devi accettare i termini per continuare.',
  code: 'Inserisci il codice di 6 cifre.',
  current_password: 'La password attuale non è corretta.',
  business_name: 'Inserisci la ragione sociale.',
  vat_number: 'Partita IVA non valida: servono IT e 11 cifre.',
  phone: 'Inserisci un numero di telefono valido.',
  address: 'Inserisci l’indirizzo dell’attività.',
  opens_at: 'Controlla l’orario di apertura (formato 08:30).',
  closes_at: 'La chiusura deve essere dopo l’apertura (formato 19:00).',
  pickup_from: 'Il ritiro deve iniziare dopo l’apertura (formato 17:00).',
  pickup_to: 'Il ritiro deve finire dopo il suo inizio e non oltre la chiusura.',
  file: 'Il file deve essere PDF, JPG o PNG, fino a 5 MB.',
  box_type_id: 'Scegli la tipologia della box.',
  category_id: 'Scegli la categoria.',
  description: 'Descrivi il contenuto (almeno 5 caratteri).',
  list_value_cents: 'Inserisci il valore di listino (almeno 0,01 €).',
  discount_pct: 'Lo sconto deve essere tra il 60% e il 70%.',
  quantity: 'La quantità deve essere almeno 1.',
  accept_no_refund: 'Per ordinare devi accettare che, se non ritiri la box, non è previsto rimborso.',
};

export function errorMessage(err) {
  if (!err) return '';
  // Il messaggio del server dice quale città ha trovato al posto di quella scritta: è già in italiano
  if (err.code === 'ADDRESS_CITY_MISMATCH' && err.message) return err.message;
  if (BY_CODE[err.code]) return BY_CODE[err.code];
  if (err.status === 422 && err.fields) {
    // Il server risponde con la chiave di Laravel (validation.unique) quando un altro venditore ha già quella P.IVA
    if (err.fields.vat_number?.some((m) => String(m).includes('unique'))) {
      return 'Questa partita IVA è già registrata da un altro venditore.';
    }
    const messages = Object.keys(err.fields).map((f) => BY_FIELD[f]).filter(Boolean);
    if (messages.length) return [...new Set(messages)].join('\n');
  }
  return 'Qualcosa è andato storto. Riprova.';
}
