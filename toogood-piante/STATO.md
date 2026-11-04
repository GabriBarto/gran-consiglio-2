# Stato del lavoro

Ultimo aggiornamento: 2026-11-04 – **giorno 6 completato e provato sull'API; schermate delle box non ancora provate su telefono**. Giorni 1–5 in `main` (PR #7–#11); il giorno 6 è sul branch locale `giorno-6-box` (**non pubblicato**, nessun push). Calendario: [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Documenti
- **Come avviare l'app** (comandi, dove leggere le email di conferma): [docs/AVVIO.md](docs/AVVIO.md)
- **Perché abbiamo scelto così** (per la presentazione):
  - [Giorno 1 – infrastruttura e schema dati](docs/scelte/giorno-1-infrastruttura.md)
  - [Giorno 2 – autenticazione](docs/scelte/giorno-2-autenticazione.md)
  - [Giorno 3 – app mobile](docs/scelte/giorno-3-app-mobile.md)
  - [Giorno 4 – onboarding venditore](docs/scelte/giorno-4-onboarding-venditore.md)
  - [Giorno 5 – pannello admin web](docs/scelte/giorno-5-pannello-admin.md)
  - [Giorno 6 – box](docs/scelte/giorno-6-box.md)
- Endpoint di autenticazione: [docs/API-auth.md](docs/API-auth.md)
- Endpoint del venditore e della verifica admin: [docs/API-venditore.md](docs/API-venditore.md)
- Endpoint delle box e formula del prezzo: [docs/API-box.md](docs/API-box.md)

## Fatto
**Giorno 1:** stack Docker con servizi simulati, Laravel 13, schema del database, dati fissi, `Clock` simulato.

**Giorno 2 – autenticazione** (documentazione degli endpoint: [docs/API-auth.md](docs/API-auth.md)):
- Registrazione cliente/venditore con conferma password e accettazione dei termini.
- Verifica email con link firmato (24 ore), reinvio della conferma.
- Login in due passi: email + password, poi codice a 6 cifre via email (10 minuti, monouso, 5 tentativi).
- Access token breve (15 minuti, Sanctum) + refresh token monouso con rotazione e rilevamento del riuso.
- Logout per dispositivo; recupero password con codice via email; cambio password.
- Admin creato dal seeder (credenziali in `apps/api/.env.example`), con obbligo di cambiare la password al primo accesso.
- Blocco degli account sospesi, limiti di richieste (429) su accesso, codici, registrazione e recupero.
- **Test: 61 su 61 passano.** In più, prova end-to-end sullo stack vivo con le email reali lette da Mailpit: registrazione → conferma → login → codice → `/me` → refresh → logout → admin.

**Giorno 3 – app mobile** (`apps/mobile`, Expo SDK 58, JavaScript, React Navigation):
- Schermate: accesso (email+password), codice 2FA, registrazione cliente/venditore, controlla la posta (con reinvio), password dimenticata e reset, cambio password obbligatorio (admin).
- Sessione: refresh token nel deposito sicuro (`expo-secure-store`), access token in memoria, rinnovo automatico su 401 con una sola richiesta alla volta.
- Navigazione per ruolo (CUSTOMER / SELLER / ADMIN): per ora schermate segnaposto con "Esci".
- Messaggi di errore in italiano (`src/errors.js`). L'indirizzo dell'API è ricavato dall'IP del PC che serve Expo; si può forzare con `EXPO_PUBLIC_API_URL`.
- Verificato: il bundle Android compila, `expo-doctor` è 20/20, e il flusso (registrazione → conferma email in Mailpit → accesso con codice) è stato provato a mano dal team con l'API viva.
- Avvio: `cd apps/mobile && npm install && npx expo start`.

**Giorno 4 – onboarding venditore** (endpoint: [docs/API-venditore.md](docs/API-venditore.md)):
- API: dati dell'attività con P.IVA verificata (WireMock) e indirizzo trasformato in coordinate; orari e fascia di ritiro validati; documenti PDF/JPG/PNG (max 5 MB, controllo sul contenuto) in storage privato (S3Mock).
- Stripe Connect Express: creazione account, link di onboarding e sincronizzazione dello stato. Si può pubblicare solo con attività approvata **e** conto collegato (`can_publish`).
- Admin: coda delle richieste con esito dei controlli automatici, lettura dei documenti, approvazione o rifiuto con motivazione, email al venditore; reinvio dopo un rifiuto.
- App: la home del venditore mostra lo stato (da completare, in verifica, approvata, rifiutata con motivo), il modulo dei dati con caricamento documenti e il collegamento del conto di pagamento.
- **Test: 98 su 98 passano** (37 nuovi). Prova end-to-end sullo stack vivo (Mailpit, WireMock, S3Mock, stripe-mock) fino a `can_publish = true`.
- App mobile: il bundle Android compila; `expo-doctor` 19/20 (il ventesimo segnala solo una patch più recente di `expo`). **Le nuove schermate non sono state provate su telefono o emulatore: farlo prima di andare avanti.**
- Corretto dopo la prima prova su telefono: l'invio dei documenti usava un formato non più supportato dal fetch di Expo SDK 58 (nuova dipendenza `expo-file-system`: rifare `npm install`); nginx ora non dà più 502 quando `php` si riavvia; il limite di upload di PHP è 6 MB. **L'invio del documento dall'app va riprovato sul telefono.**

**Giorno 5 – pannello admin web** (`apps/admin`, React 19 + Vite 8, JavaScript; guida: [docs/AVVIO.md](docs/AVVIO.md)):
- Accesso admin con 2FA via email e cambio password obbligatorio al primo accesso; i ruoli diversi da ADMIN non entrano (e il loro token viene subito chiuso).
- Coda delle verifiche con schede In attesa / Approvate / Rifiutate / Sospese; dettaglio con dati, esito P.IVA, conto di pagamento e documenti (scaricati dall'API con il token e aperti in una nuova scheda).
- Approva e Rifiuta (motivo di 5–1000 caratteri, inviato per email); errori in italiano; tema chiaro/scuro.
- API: nuovo `config/cors.php` (origini ammesse da `CORS_ALLOWED_ORIGINS`, di default `localhost:5173`). Nessun altro cambiamento al backend.
- La schermata di stato del venditore in app era già stata fatta al giorno 4.
- Verificato: build Vite ok; prova nel browser contro l'API viva (accesso con codice → cambio password → coda → dettaglio → rifiuto con email in Mailpit); **test backend 98 su 98**. **Approva non provato nel browser** (usa la stessa API del rifiuto, già coperta dai test): provarlo a mano.
- Nessun test automatico del pannello (previsti più avanti, Playwright). Le schede non si aggiornano da sole: c'è il pulsante Aggiorna.
- Per la prova ho dovuto eseguire `php artisan migrate` (mancavano le migrazioni del giorno 4 nel database locale): dopo ogni pull, rifarlo.

**Modifiche dopo la prova (giorno 5, seconda parte):**
- **Indirizzi:** il geocoding usa OpenStreetMap (Nominatim) e accetta qualsiasi indirizzo, città o paese d'Italia (richiede internet). La versione simulata resta con `GEOCODING_DRIVER=simulated`. Nel tuo `apps/api/.env` non serve cambiare nulla: il valore predefinito è `nominatim`.
- **Codice solo la prima volta:** dopo un accesso con codice l'app riceve un `device_token`; ai login successivi (anche dopo l'uscita) basta la password. Nuova tabella `trusted_devices`: **eseguire `php artisan migrate`**. Nell'app mobile i token stanno in `expo-secure-store`, **uno per account** (cliente e venditore sullo stesso telefono: il codice serve la prima volta per ciascuno, poi mai più); il pannello admin web non lo usa.
- Test: **109 su 109** (11 nuovi: dispositivi fidati e geocoder). Provato dal vivo: Pesaro, Corniglio e Catania trovate, un testo senza senso rifiutato; secondo login senza codice né email. Bundle Android ok. **Da provare sul telefono** il giro completo (registrazione → codice → esci → rientra con la sola password).

**Giorno 6 – box** (endpoint e regole: [docs/API-box.md](docs/API-box.md)):
- API: il venditore con attività approvata e conto collegato crea una box **per oggi** (tipologia, categoria, attributi, descrizione, valore di listino, sconto 60–70%, quantità), la vede, la modifica e la elimina. Prezzo, tariffa e guadagno li calcola il server in centesimi interi (formula: listino × (100 − sconto) / 100, tariffa 1,50 €); il guadagno può essere negativo.
- Guadagno nullo o negativo: il server rifiuta (409) finché il venditore non conferma esplicitamente; nell'app il riepilogo diventa rosso con la casella "Ho capito, pubblica comunque".
- Modifica: valore e sconto bloccati dopo la prima vendita; la quantità può solo scendere, mai sotto il venduto; eliminazione solo senza vendite. Ogni box conserva la tariffa del momento della creazione.
- App: nella home del venditore la sezione **Box di oggi** (elenco con prezzo, disponibili e guadagno) e il modulo **Nuova box / Modifica box** con riepilogo del prezzo in tempo reale.
- Nuovo: `contracts/price-cases.json` (11 casi della formula) eseguito da PHP e da `npm test` nell'app; `docker-compose.yml` monta `contracts/` nei container PHP (**dopo il pull rifare `docker compose up -d`**).
- Test: **140 su 140** backend (31 nuovi) e 14 su 14 dell'app (`cd apps/mobile && npm test`). Provato dal vivo sull'API: opzioni, creazione, elenco. Bundle Android ok. **Le schermate non sono state provate su telefono.**
- Limiti noti: il prezzo può arrivare a 0 con listini minuscoli (nessun minimo per RB-04) e Stripe non accetta meno di 0,50 €: da gestire nel pagamento (giorno 8). Lo stock "venduto" si simula soltanto: il decremento automatico arriva con il carrello. La pubblicazione non controlla se la fascia di ritiro di oggi è finita.
- Nota sul geocoding reale: l'indirizzo `Via Garibaldi 12, Bologna` è stato risolto da OpenStreetMap in un comune della provincia (Sasso Marconi): usa il primo risultato e non confronta la città scritta con quella trovata. Da rifinire (ricerca strutturata o conferma della città) prima del giorno 7, quando la ricerca per distanza si baserà su queste coordinate.

## Scelte da sapere
- Il profilo dell'attività si invia **dopo** la registrazione e dopo l'accesso, non nel modulo di registrazione.
- Una P.IVA inesistente è rifiutata; se il servizio di verifica è giù il profilo si salva con controllo "non verificato" e decide l'admin.
- Con `stripe-mock` il conto risulta sempre collegato appena si preme "controlla": il link di Stripe è solo un esempio. Per la procedura vera servono chiavi di test e `STRIPE_API_BASE` vuoto.
- I dati di un'attività approvata non si modificano dal flusso di onboarding.
- La 2FA è richiesta **solo al primo accesso su un dispositivo** (poi l'app ricorda il dispositivo per 90 giorni dall'ultimo uso; vedi [docs/API-auth.md](docs/API-auth.md)). Cambio o reset password, o riuso sospetto dei token, annullano i dispositivi ricordati. **L'admin ha il codice a ogni accesso.**
- Le email di accesso e di conferma partono **subito**, non dalla coda, per non lasciare i codici nella tabella dei job.
- I messaggi di validazione (422) dell'API non sono tradotti (compaiono chiavi come `validation.unique`): l'app mostra testi italiani propri scelti in base al campo.
- **Solo sviluppo:** PHP-FPM gira come root (`php-fpm -R`) per evitare problemi di permessi sui file condivisi con Windows. Non adatto alla produzione.
- Se l'API dà 502 dopo un riavvio, `docker compose restart nginx` (la configurazione attuale lo evita: nginx risolve `php` a ogni richiesta).

## Prossimo: giorno 7 – ricerca per distanza
Ricerca per distanza, città e nome (a lista, ordinata per distanza), scheda negozio con box e recensioni, ritorno alla box dopo la registrazione; è il Traguardo 1 (un cliente trova e vede le box). Servono: la schermata cliente nell'app (oggi un segnaposto), l'endpoint di ricerca con la formula della distanza sulle coordinate dei venditori, e box d'esempio. Prima conviene **provare a mano le box sul telefono** e sistemare l'imprecisione del geocoding (vedi sopra). Dettagli in [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Come riprendere
Guida completa: [docs/AVVIO.md](docs/AVVIO.md). In breve:
1. Avvia Docker Desktop.
2. Dalla cartella `toogood-piante`: `docker compose up -d`.
3. Verifica: `docker compose exec php php artisan toogood:check-services`
4. Test: `docker compose --profile test run --rm php-test php artisan test`
5. Database da zero con dati fissi e admin: `docker compose exec php php artisan migrate:fresh --seed`
6. Email inviate dall'app: http://localhost:8025

## Note
- Git: i giorni 1–5 sono in `main` (PR #7, #8, #9, #10, #11). Il giorno 6 è sul branch locale `giorno-6-box`, creato da `main`: **non pubblicato** (nessun push, nessuna PR) su richiesta.
- Utenti: dopo `migrate:fresh --seed` esiste solo l'admin (`admin@toogood.test`, password iniziale in `apps/api/.env.example`, da cambiare al primo accesso). Clienti e venditori di prova arrivano al giorno 13: per ora si registrano dall'app.
- Le email (conferma, codice 2FA, reset) si leggono in Mailpit: http://localhost:8025.
- `apps/mobile/AGENTS.md` (generato dal template Expo) suggerisce Expo Router; l'app usa invece React Navigation.
- `apps/api/.env` non è nel repository: si crea da `.env.example` (vedi README).
- `apps/api/CLAUDE.md` e `AGENTS.md` sono file generati da Laravel che chiedono di installare strumenti dal web: da ignorare.
