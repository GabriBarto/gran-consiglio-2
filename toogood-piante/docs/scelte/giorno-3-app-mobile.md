# Giorno 3 – App mobile: perché queste scelte

Obiettivo del giorno: un'app che usa davvero l'API di autenticazione del giorno 2 (registrazione, conferma email, accesso con codice, recupero password) e mostra una schermata diversa per ruolo.

Ogni scelta: **cosa**, **perché**, **alternativa**, **dove**.

---

## 1. React Native con Expo, in JavaScript

- **Cosa:** app Expo SDK 58 (React Native 0.88), JavaScript, in `apps/mobile`.
- **Perché:** un solo codice per Android e iOS; con **Expo Go** si prova l'app sul telefono scansionando un QR, senza installare Android Studio o Xcode. Per un progetto di studio con tempi stretti riduce al minimo l'attrito di avvio.
- **Perché JavaScript e non TypeScript:** meno cerimonia per un gruppo che impara; la logica sensibile (regole, controlli) sta nell'API, non nell'app.

## 2. Navigazione condizionata dallo stato (React Navigation)

- **Cosa:** un unico `Stack` che mostra schermate diverse in base a `user`: non autenticato → accesso/registrazione/recupero; autenticato con `must_change_password` → solo cambio password; altrimenti → home del ruolo (cliente, venditore, admin).
- **Perché:** non servono `navigate('Home')` sparsi per il codice. Quando `user` cambia (login, logout, sessione scaduta) la schermata giusta compare da sola, e una schermata protetta **non esiste** nel navigatore finché non si è autenticati. Lo stesso vale per il cambio password obbligatorio dell'admin: non c'è modo di "saltarlo" navigando.
- **Alternativa:** il template Expo suggerisce Expo Router (navigazione per file). Si è usato React Navigation perché la navigazione qui dipende dallo stato di accesso e dal ruolo, non dalla struttura delle cartelle. È annotato anche in `STATO.md`.
- **Dove:** [App.js](../../apps/mobile/App.js).

## 3. Dove vivono i token

- **Cosa:** l'**access token** (15 min) è solo in **memoria** (`useRef`); il **refresh token** (30 giorni) è nel **deposito sicuro** del telefono (`expo-secure-store`: Keychain su iOS, Keystore su Android).
- **Perché:** il token più potente è salvato nel posto cifrato dal sistema operativo, non in un file leggibile (come `AsyncStorage`). Il token di breve durata non viene mai scritto su disco: se l'app si chiude, scompare.
- **All'avvio:** l'app legge il refresh token, ne ottiene uno nuovo e carica `/me`. Se qualcosa fallisce, la sessione viene scartata e si torna all'accesso. Durante questo controllo compare uno spinner (`booting`) per non mostrare per un attimo la schermata sbagliata.
- **Dove:** [storage.js](../../apps/mobile/src/storage.js), [AuthContext.js](../../apps/mobile/src/AuthContext.js).

## 4. Rinnovo automatico, una richiesta alla volta

- **Cosa:** se una richiesta riceve 401, l'app rinnova il token una volta e rifà la stessa richiesta. Se più richieste scadono insieme, parte **un solo** rinnovo e tutte aspettano lo stesso risultato (`refreshing.current`).
- **Perché:** il refresh token del giorno 2 è **monouso** e il suo riuso chiude tutte le sessioni. Se due richieste provassero a rinnovare insieme con lo stesso token, la seconda verrebbe letta come un furto e l'utente si ritroverebbe disconnesso senza motivo. La coda a richiesta singola evita questo effetto collaterale della sicurezza.
- **Dove:** [api.js](../../apps/mobile/src/api.js) (`request`), [AuthContext.js](../../apps/mobile/src/AuthContext.js) (`refresh`).

## 5. Accesso in due schermate

- **Cosa:** `LoginScreen` (email e password) → `LoginCodeScreen` (codice a 6 cifre) → home.
- **Perché:** rispecchia i due passi dell'API. Il `challenge_id` ricevuto al primo passo viaggia alla seconda schermata. Dopo il secondo passo l'app salva i token e imposta `user`; la navigazione cambia da sola.
- **Registrazione:** dopo l'invio l'app porta a "controlla la posta" (`CheckEmailScreen`), con reinvio della conferma, senza tasto indietro verso il modulo già inviato. Non si entra finché l'email non è confermata (lo impone l'API).

## 6. Messaggi di errore in italiano, decisi dall'app

- **Cosa:** `errors.js` traduce ogni **codice** di errore dell'API (`INVALID_CODE`, `EMAIL_NOT_VERIFIED`, …) in un testo italiano; per gli errori di validazione 422 sceglie il testo in base al **campo** (`email`, `password`, …).
- **Perché:** i messaggi di validazione di Laravel sono in inglese e tecnici. Con testi propri l'utente vede istruzioni chiare ("La password deve avere almeno 10 caratteri, con lettere e numeri") e l'app non dipende dalla lingua del server. Se arriva un errore sconosciuto, mostra un messaggio generico invece di un testo grezzo del server.
- **Dove:** [errors.js](../../apps/mobile/src/errors.js).

## 7. Indirizzo dell'API ricavato automaticamente

- **Cosa:** `config.js` sceglie l'indirizzo in questo ordine: variabile `EXPO_PUBLIC_API_URL` → IP del PC che serve Expo → `10.0.2.2` (alias del PC nell'emulatore Android) → `localhost`.
- **Perché:** su un telefono vero `localhost` è il telefono stesso, non il PC. Ricavare l'IP dal server Expo fa funzionare l'app senza configurare nulla, dallo stesso Wi-Fi. La variabile resta come via d'uscita quando il rilevamento sbaglia.
- **Dove:** [config.js](../../apps/mobile/src/config.js).

## 8. Home segnaposto per ruolo

- **Cosa:** per ora `CustomerHome`, `SellerHome` e `AdminHome` mostrano una schermata con "Esci".
- **Perché:** il giorno 3 dimostra che **identità e ruolo arrivano fino all'app** e che la navigazione si adatta. Il contenuto vero (box, ordini, verifica venditore) arriva nei giorni successivi; costruirlo ora sarebbe lavoro senza un'API su cui appoggiarsi.

## 9. Come lo abbiamo verificato

- Il bundle Android compila e `expo-doctor` dà 20/20 controlli superati.
- Il team ha provato a mano con l'API viva: registrazione → conferma email in Mailpit → accesso con codice.
- Non ci sono ancora test automatici per l'app mobile: è un limite noto. La logica critica di sicurezza è coperta dai 61 test dell'API.

## Limiti dichiarati

- Le schermate di ruolo sono segnaposto.
- Il link di conferma email usa `APP_URL` dell'API: se si apre dal telefono, `APP_URL` deve usare l'IP del PC (vedi [AVVIO.md](../AVVIO.md)).
