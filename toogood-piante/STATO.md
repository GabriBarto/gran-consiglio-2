# Stato del lavoro

Ultimo aggiornamento: 2026-11-07 – **giorni 1–13 completati e provati a mano dal team** (giorni 12 e 13 provati il 2026-11-07 su telefono, nel pannello admin e con `stripe-mock`, compresi lo storno e la concorrenza). Tutto in `main` (PR #7–#21). Il giorno 14 (cuscinetto) è in corso: copione della demo e README fatti, verifiche finali eseguite. Calendario: [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Documenti
- **Come avviare l'app** (comandi, dove leggere le email di conferma): [docs/AVVIO.md](docs/AVVIO.md)
- **Perché abbiamo scelto così** (per la presentazione):
  - [Giorno 1 – infrastruttura e schema dati](docs/scelte/giorno-1-infrastruttura.md)
  - [Giorno 2 – autenticazione](docs/scelte/giorno-2-autenticazione.md)
  - [Giorno 3 – app mobile](docs/scelte/giorno-3-app-mobile.md)
  - [Giorno 4 – onboarding venditore](docs/scelte/giorno-4-onboarding-venditore.md)
  - [Giorno 5 – pannello admin web](docs/scelte/giorno-5-pannello-admin.md)
  - [Giorno 6 – box](docs/scelte/giorno-6-box.md)
  - [Giorno 7 – ricerca e scheda negozio](docs/scelte/giorno-7-ricerca.md)
  - [Giorno 8 – ordini, stock riservato e pagamenti](docs/scelte/giorno-8-ordini.md)
  - [Giorno 9 – app cliente: carrello, pagamento e storico](docs/scelte/giorno-9-app-ordini.md)
  - [Giorno 10 – QR di ritiro](docs/scelte/giorno-10-qr-ritiro.md)
  - [Giorno 11 – payout ritardato e incassi](docs/scelte/giorno-11-payout.md)
  - [Giorno 12 – recensioni, reclami e rimborsi](docs/scelte/giorno-12-reclami.md)
  - [Giorno 13 – rifinitura: sospensione, casi limite, dati demo](docs/scelte/giorno-13-rifinitura.md)
- Endpoint di autenticazione: [docs/API-auth.md](docs/API-auth.md)
- Endpoint del venditore e della verifica admin: [docs/API-venditore.md](docs/API-venditore.md)
- Endpoint delle box e formula del prezzo: [docs/API-box.md](docs/API-box.md)
- Endpoint di ricerca e scheda dei negozi (pubblici): [docs/API-negozi.md](docs/API-negozi.md)
- Endpoint degli ordini, dei pagamenti e del webhook Stripe: [docs/API-ordini.md](docs/API-ordini.md)
- Endpoint del ritiro con QR (biglietto, scansione, ordini del giorno): [docs/API-ritiro.md](docs/API-ritiro.md)
- Endpoint degli incassi e pagamenti ai venditori: [docs/API-incassi.md](docs/API-incassi.md)
- Endpoint di recensioni, reclami e rimborsi: [docs/API-reclami.md](docs/API-reclami.md)

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
- API: il venditore con attività approvata e conto collegato crea una box **per oggi** (tipologia, categoria, attributi, descrizione **facoltativa**, valore di listino, sconto 60–70%, quantità), la vede, la modifica e la elimina. Prezzo, tariffa e guadagno li calcola il server in centesimi interi (formula: listino × (100 − sconto) / 100, tariffa 1,50 €); il guadagno può essere negativo.
- Guadagno nullo o negativo: il server rifiuta (409) finché il venditore non conferma esplicitamente; nell'app il riepilogo diventa rosso con la casella "Ho capito, pubblica comunque".
- Modifica: valore e sconto bloccati dopo la prima vendita; la quantità può solo scendere, mai sotto il venduto; eliminazione solo senza vendite. Ogni box conserva la tariffa del momento della creazione.
- App: nella home del venditore la sezione **Box di oggi** (elenco con prezzo, disponibili e guadagno) e il modulo **Nuova box / Modifica box** con riepilogo del prezzo in tempo reale.
- Nuovo: `contracts/price-cases.json` (11 casi della formula) eseguito da PHP e da `npm test` nell'app; `docker-compose.yml` monta `contracts/` nei container PHP (**dopo il pull rifare `docker compose up -d` e `php artisan migrate`**: la descrizione della box è ora facoltativa).
- Test: **150 su 150** backend (31 sulle box, 8 sul geocoding, 2 sulla descrizione facoltativa) e 14 su 14 dell'app (`cd apps/mobile && npm test`). Provato dal vivo sull'API e **dall'app sul telefono: creazione, modifica ed eliminazione funzionano**. Bundle Android ok.
- Limiti noti: il prezzo può arrivare a 0 con listini minuscoli (nessun minimo per RB-04) e Stripe non accetta meno di 0,50 €: da gestire nel pagamento (giorno 8). Lo stock "venduto" si simula soltanto: il decremento automatico arriva con il carrello. La pubblicazione non controlla se la fascia di ritiro di oggi è finita.
- **Geocoding corretto (stesso giorno):** prima OpenStreetMap restituiva il miglior civico anche in un altro comune (`Via Garibaldi 12, Bologna` → Sasso Marconi) e l'app lo accettava. Ora si chiedono 8 risultati e si sceglie quello il cui comune (o frazione/quartiere) **coincide per nome** con la città scritta dopo l'ultima virgola (ignorando accenti, maiuscole, articoli, CAP e sigla di provincia; la provincia non conta). Se non c'è, l'errore `ADDRESS_CITY_MISMATCH` dice dove si trova l'indirizzo più simile e nulla viene salvato. Con la sola città (`Pesaro`) non si confronta. Regola per chi inserisce un indirizzo: **via, numero, città** con la virgola; se OpenStreetMap non conosce quel civico nella città, va usato un altro indirizzo. Esempi che funzionano: `Via Rizzoli 5, Bologna`, `Via Rossini 10, Pesaro`, `Via Torino 10, Milano`.

**Giorno 7 – ricerca per distanza e scheda negozio** (endpoint: [docs/API-negozi.md](docs/API-negozi.md)) — **Traguardo 1**:
- API pubblica (senza accesso, 60 richieste/minuto per IP): `GET /shops` cerca per distanza (`lat`,`lng`, ordina per metri in linea d'aria con `ST_Distance_Sphere`), raggio (`radius_km`), città e nome (contiene, senza maiuscole né accenti), con `include_empty=1` anche i negozi senza box oggi. `GET /shops/{id}`: orari, fascia di ritiro di oggi, orario limite d'ordine, media e recensioni, box di oggi con prezzo, risparmio minimo ("almeno X €"), attributi e stock; le esaurite restano visibili in fondo.
- Compaiono solo negozi approvati e con conto collegato; partita IVA, telefono, email e dati Stripe non escono mai (c'è un test).
- `OrderWindow`: orario limite = fine ritiro − 2 ore (parametro `order_cutoff_hours`), deciso dal server; sarà riusato dal carrello.
- App: si apre sull'elenco dei negozi (non più sul login). Chiede la posizione all'avvio (se negata si cerca per città o nome). Scheda negozio con box. Visitatore: "Accedi per acquistare" salva negozio e box su disco e porta al login; dopo il **primo accesso da cliente** si torna alla scheda con la box evidenziata, con messaggio se nel frattempo è esaurita, eliminata o oltre l'orario limite. Cliente: vede le box ma l'acquisto arriva col carrello (giorno 8).
- Nuovi: `expo-location` (permesso della posizione nell'`app.json`: serve un **`npm install`** dopo il pull), `docs/API-negozi.md`.
- Test: **168 su 168** backend (18 nuovi sulla ricerca: ordine e distanze verificati con Haversine, filtri, privacy, orario limite, recensioni) e 15 su 15 dell'app. Provato dal vivo sull'API con tre negozi di prova (Bologna, Pesaro, Milano: Pesaro a 141 km da Bologna). Bundle Android ok. **L'app è stata provata a mano sul telefono: ricerca e schede funzionano.**
- Limiti noti: nessuna paginazione (max 50 negozi); il nome non tollera i refusi (roadmap R3); niente vista su mappa né filtri per categoria/prezzo (roadmap R3); recensioni vuote finché non esistono (giorno 12).
- Per provare serve almeno un negozio **approvato, con conto collegato e una box di oggi** (il giorno 13 aggiungerà dati di prova). La fascia di ritiro del negozio decide fino a quando si ordina: se è già passata l'orario limite, le box risultano "Ordini chiusi" (si può simulare con `php artisan clock advance` / `reset`).

**Giorno 8 – ordini, stock riservato, pagamento e webhook** (endpoint: [docs/API-ordini.md](docs/API-ordini.md)) — solo lato server; carrello, checkout e storico nell'app sono il **giorno 9**:
- `POST /orders` (solo clienti): dal carrello (righe box+quantità, un solo negozio) crea un ordine **in attesa di pagamento**, tiene da parte le box per **10 minuti** e crea il PaymentIntent. Controlla: casella "nessun rimborso" accettata, orario limite (`OrderWindow`), negozio vendibile, box di oggi, stock, minimo di Stripe (0,50 €). Se manca anche una sola box non si prenota nulla e non si crea alcun pagamento. Prezzo e tariffa sono copiati dalla box (istantanea). Rifare il carrello libera l'ordine in attesa precedente.
- `GET /orders/{id}` (proprio ordine). Stati: `PENDING_PAYMENT → PAID`, `PAYMENT_FAILED`, `EXPIRED`, `REFUNDED_FULL`.
- `POST /webhooks/stripe`: firma verificata (segreto `STRIPE_WEBHOOK_SECRET`), idempotente (tabella `stripe_events` + controllo dello stato con riga bloccata). `succeeded` → pagato + email al cliente (conferma) e al venditore (nuovo ordine); `payment_failed` → stock restituito; **pagamento tardivo** (ordine già scaduto) → rimborso automatico per intero + email. Se il rimborso fallisce il webhook risponde 500 e Stripe riprova.
- Comando `reservations:release` ogni minuto (scheduler): libera le prenotazioni scadute e annulla il PaymentIntent. In sviluppo: `php artisan clock advance 11m`, poi `reservations:release`, poi `clock reset`.
- **Pagamento simulato** (solo sviluppo, `PAYMENTS_SIMULATED`): `POST /orders/{id}/simulate-payment` passa dallo stesso codice del webhook vero (con `stripe-mock` non ci sono carte né webhook). Attivo da solo se `STRIPE_API_BASE` punta a stripe-mock; 404 con Stripe vero.
- Nuovi: modelli `Order`, `OrderItem`, `StockReservation`; servizi `CheckoutService`, `OrderLifecycle`, `PaymentEventHandler`, `PaymentService`; 3 email; variabili `STRIPE_PUBLISHABLE_KEY` e `PAYMENTS_SIMULATED` in `.env.example`. **Nessuna migrazione** (le tabelle c'erano dal giorno 1).
- Test: **206 su 206** backend (38 nuovi: checkout, stock, orario limite, firma del webhook, idempotenza, pagamento fallito, pagamento tardivo con rimborso, scadenza). Provato dal vivo con **lo stripe-mock vero**: ordine, pagamento simulato, due email in Mailpit, webhook con firma vera (sbagliata → 400, giusta → pagato, ripetuta → ignorata), scadenza con orologio simulato.
- **Provato con richieste parallele** (8 e 16 acquisti insieme su una box da 3 pezzi): sempre 3 box tenute da parte, mai sotto zero. Il primo tentativo ha rivelato un **deadlock di MySQL** (un errore 500 su 8, senza danni allo stock): corretto togliendo un blocco a intervallo e ripetendo la transazione in caso di deadlock (dettagli nelle note sulle scelte).
- Limiti noti: manca il QR di ritiro (giorno 10) e il pagamento al venditore (giorno 11), anche se l'email di conferma li menziona già; nessun elenco ordini; con Stripe vero servono chiavi di test e il profilo Docker `stripe` per i webhook (mai provato: si è usato il simulatore).
- Per provare a mano senza l'app serve un cliente con un token (vedi [docs/AVVIO.md](docs/AVVIO.md)).

**Giorno 9 – app cliente: carrello, pagamento e storico ordini** — **Acquisto completo dall'app** (in modalità prova):
- Scheda negozio: per il cliente ogni box ha quantità (− e +, entro lo stock) e **Aggiungi al carrello**; un altro negozio nel carrello → conferma e lo svuota (un carrello, un negozio). In alto, "Vai al carrello". Nella ricerca, due scorciatoie: **Carrello** e **I miei ordini**.
- **Carrello**: quantità, totale, "risparmi almeno", fascia di ritiro, orario limite d'ordine; si **riallinea con il negozio** ogni volta che si apre (toglie box esaurite o non più ordinabili, riduce le quantità, avvisa se il prezzo è cambiato).
- **Pagamento**: riepilogo, avviso rosso "nessun rimborso" con **casella obbligatoria**, poi ordine con **conto alla rovescia dei 10 minuti**. In prova: "Paga (prova)" (passa dal codice del webhook vero) e "Simula un pagamento fallito". `OUT_OF_STOCK`: elenca le box che mancano e conferma che non è stato addebitato nulla.
- **Conferma** dopo il pagamento (la navigazione riparte da "negozi → ordine") e **storico**: tutti gli ordini con stato (In attesa di pagamento, Pagato, Rimborsato…); dal dettaglio di un ordine in attesa si può ancora pagare.
- API: nuovo `GET /orders` (ultimi 50; non mostra gli ordini scaduti o falliti) e `payment_simulated` nel dettaglio.
- Nuovi file: `src/cart.js`, `src/orders.js` (logica pura), `src/CartContext.js`, `src/screens/OrderScreens.js`; il carrello è in memoria e si svuota cambiando utente.
- Test: **212 su 212** backend (6 nuovi sullo storico) e **37 su 37** dell'app (22 nuovi su carrello e ordini). Un controllo d'integrazione ha percorso l'intero giro dell'app (ricerca → scheda → carrello → ordine → `OUT_OF_STOCK` per un secondo cliente → pagamento → storico → pagamento fallito) con la logica reale dell'app contro l'API vera: tutto d'accordo. Bundle Android ok. **Le schermate non sono state provate su telefono.**
- Limiti noti: la **carta vera** non c'è (servirebbe il modulo di Stripe, chiavi di test e una build dedicata: con Expo Go non basta): in app c'è solo il pagamento di prova, e con un server non in modalità prova l'app lo dice e non mostra il pulsante; il QR di ritiro è il giorno 10 (la schermata dell'ordine lo annuncia); il carrello si perde se si chiude l'app.
- Per provare servono un negozio approvato, con conto collegato e una box di oggi, e un account **cliente**: vedi [docs/AVVIO.md](docs/AVVIO.md).

**Giorno 10 – QR di ritiro, scansione e stati dell'ordine** (endpoint: [docs/API-ritiro.md](docs/API-ritiro.md)):
- **Biglietto:** ogni ordine pagato ha un **QR firmato** (solo numero d'ordine e identificativo casuale, firmati con HMAC: nessun dato personale) e un **codice alternativo** `ABCD-EFGH` (senza 0 O 1 I L). Nasce al pagamento, valido **solo nella fascia di ritiro del giorno** (estremi inclusi) e **monouso**. Il codice è anche nell'email di conferma. `GET /orders/{id}/pickup` lo restituisce al cliente.
- **Scansione:** `POST /seller/pickup/scan` con `{qr}` o `{code}` (minuscole e spazi non contano). Esiti spiegati: `INVALID_QR`, `INVALID_CODE`, `WRONG_SHOP`, `ALREADY_USED` (con l'ora), `NOT_YET_VALID` (con l'ora di inizio), `EXPIRED`, `ORDER_NOT_ACTIVE`. Se riesce l'ordine diventa `PICKED_UP` con data e ora. **Una sola scansione passa** anche con richieste simultanee (provato dal vivo: 16 insieme → 1 riuscita, 15 rifiutate, nessun errore). Un venditore sospeso ritira comunque gli ordini già pagati.
- **Ordini del giorno:** `GET /seller/orders` con riepilogo (da ritirare, ritirati, non ritirati) e, per ogni ordine, **solo il nome utente** del cliente.
- **Fine fascia:** comando `orders:close-pickup` ogni minuto → `NOT_PICKED_UP` (nessun rimborso). Chiude solo *dopo* la fine della fascia, coerente con la scansione.
- **App cliente:** nel dettaglio di un ordine pagato compare il **QR** (con `react-native-qrcode-svg`), il codice alternativo e lo stato (non ancora iniziato / in corso / finito / ritirato). Il biglietto si **salva sul telefono** appena caricato e si vede anche senza rete; lo stato si ricalcola sul telefono.
- **App venditore:** nella home la scheda **Ritiri di oggi** (conteggi e ordini con stato) e **Scansiona il QR** (fotocamera con `expo-camera`, oppure codice scritto a mano); l'esito è verde ("Ritiro confermato") o rosso con il motivo.
- Nuovi: `PickupToken`, `PickupService`, `PickupController`, `SellerOrderController`, `CloseExpiredPickups`; `src/qrStore.js`, `src/screens/PickupScreens.js`; librerie `expo-camera`, `react-native-svg`, `react-native-qrcode-svg` (permesso fotocamera nell'`app.json`: **dopo il pull serve `npm install` in `apps/mobile`**). **Nessuna migrazione** (la tabella `pickup_tokens` c'era dal giorno 1).
- Test: **232 su 232** backend (20 nuovi: biglietto, firma falsificata, bordi della fascia, doppia scansione, altro negozio, ordini del giorno, privacy, fine fascia) e **42 su 42** dell'app (5 nuovi). Provato dal vivo con negozio e clienti di prova: QR falso rifiutato, altro negozio rifiutato, **16 scansioni simultanee → 1 sola riuscita**, ritiro con codice scritto male (minuscole e spazi), ordini del giorno, chiusura di fine fascia con l'orologio simulato. Bundle Android ok. **QR e scansione con la fotocamera provati dal team sul telefono: funzionano.**
- **Attenzione, effetto collaterale dell'orologio simulato:** `clock advance` vale per tutto il database di sviluppo. Durante la prova ha chiuso come "non ritirato" anche un tuo ordine reale di prova (#23, "Vivaio Notte"), che ho **rimesso a `PAID`**. Dopo ogni prova con `clock advance` conviene controllare gli ordini (vedi [docs/AVVIO.md](docs/AVVIO.md)).
- Limiti noti: il QR vale per il giorno dell'ordine; nessun annullamento di una scansione sbagliata; il payout al venditore è il giorno 11; la prova su dispositivo è stata fatta a mano dal team, non è automatica.

**Giorno 11 – payout ritardato e incassi del venditore** (endpoint: [docs/API-incassi.md](docs/API-incassi.md)):
- **Payout:** quando l'ordine risulta pagato nasce una riga `payouts` `SCHEDULED` da eseguire a **fine fascia di ritiro + 24 ore** (parametro `payout_delay_hours` in `config_params`). Vale sia per gli ordini ritirati sia per quelli non ritirati. Importo = `Σ quantità × max(0, prezzo − tariffa)`, per box: una box in perdita non intacca le altre e non si addebita mai nulla al venditore.
- **Esecuzione:** comando `payouts:run` ogni 10 minuti: trasferimento Stripe Connect (con `stripe-mock`: simulato) → `SENT`; importo 0 o ordine rimborsato per intero → `SKIPPED`; ordine `IN_COMPLAINT` → `HELD`. Un solo pagamento alla volta (riga bloccata) e chiave di idempotenza `payout-<id>`: rilanciarlo non paga due volte. Se Stripe non risponde il payout resta `SCHEDULED`, riprova al giro dopo, gli altri non si fermano e il comando esce con codice 1. Crea anche i payout mancanti degli ordini pagati prima del giorno 11.
- **Incassi:** `GET /seller/earnings` con riepilogo (ordini, incassato, tariffe, guadagno anche **negativo**, trasferito, in attesa, sospeso) ed elenco degli ordini con lo stato del pagamento; niente dati dei clienti; gli ordini rimborsati per intero non contano.
- **App venditore:** nella home, sotto "Ritiri di oggi", la scheda **Incassi** (anche per un negozio sospeso). Nuovi: `src/earnings.js` (logica pura), `src/screens/EarningsScreens.js`. Nessuna nuova dipendenza.
- Nuovi: `Payout`, `PayoutService`, `TransferService`, `RunPayouts`, `EarningsController`. **Nessuna migrazione** (la tabella `payouts` c'era dal giorno 1).
- Test: **253 su 253** backend (21 nuovi: calcolo dell'importo, data di esecuzione, ritardo configurabile, ritirato e non ritirato, doppia esecuzione, errore di Stripe, reclamo, rimborso, ordini precedenti, riepilogo, privacy) e **48 su 48** dell'app (6 nuovi). Provato sul vero `stripe-mock`: il trasferimento va a buon fine, e `payouts:run` sul database di sviluppo ha programmato il payout dell'ordine esistente (76,20 €) senza inviare nulla. Bundle Android ok.
- **Provato dal team sul telefono:** la scheda Incassi mostra l'importo "in attesa"; dopo `clock advance 26h` e `payouts:run` passa a "Trasferito". Nota: lo scheduler dei container esegue `payouts:run` da solo ogni 10 minuti, quindi il pagamento può partire anche senza lanciarlo a mano. Dopo la prova: `clock reset` (vedi [docs/AVVIO.md](docs/AVVIO.md)).
- Limiti noti: nessuna email al venditore quando il pagamento parte; rimborso parziale e storno di un trasferimento già inviato (`REVERSED`) sono arrivati col giorno 12; con Stripe vero servono chiavi di test e il conto Connect con `transfers` attivo (mai provato); la chiamata a Stripe avviene con la riga bloccata (va bene per poche decine di payout al giorno).

**Giorno 12 – recensioni, reclami e rimborsi** (endpoint: [docs/API-reclami.md](docs/API-reclami.md)) — **Traguardo 2**:
- **Recensione** (US-C12): `POST /orders/{id}/review`, voto 1–5 e commento facoltativo, solo se l'ordine è stato ritirato, una per ordine (vincolo del database). Compare nella scheda pubblica del negozio (già predisposta al giorno 7) e al venditore in `GET /seller/reviews`.
- **Reclamo** (US-C14): `POST /orders/{id}/complaints`, solo testo, nei quattro casi ammessi. Termine di **24 ore** (`complaint_window_hours`) deciso dal server: dal ritiro per i casi b–d (valore inferiore al dichiarato, piante danneggiate, caratteristiche non rispettate), da inizio fascia a fine fascia + 24 ore per il caso a (negozio chiuso / box non consegnata, solo su ordini non ritirati). Un solo reclamo per ordine. Apertura → ordine `IN_COMPLAINT`, payout `HELD`; il comando di fine fascia non tocca gli ordini in reclamo.
- **Decisione dell'admin** (US-S05): `POST /admin/complaints/{id}/decide` con `ACCEPT_FULL`, `ACCEPT_PARTIAL` (importo da 1 centesimo a totale − 1) o `REJECT`, più motivazione. Rimborso su Stripe con chiave di idempotenza `complaint-<id>-refund`; ordine → `REFUNDED_FULL` / `REFUNDED_PARTIAL` / `COMPLAINT_REJECTED`; riga in `refunds`. **Payout:** totale → `SKIPPED`; parziale → ridotto dello stesso importo (mai sotto zero, l'eccedenza la assorbe la piattaforma); rifiuto → di nuovo `SCHEDULED`; se il trasferimento era già partito, **storno** su Stripe (`REVERSED`, o importo ridotto). Se Stripe non risponde: `502`, nulla viene salvato, si può ripetere.
- **Email:** al venditore all'apertura; a cliente e venditore con l'esito (importo e motivazione).
- **Pannello admin web:** nuova scheda **Reclami** (coda da decidere con contatore, storico, dettaglio con valore di listino dichiarato, email del cliente e stato del payout, decisione con importo in euro validato).
- **App cliente:** nel dettaglio dell'ordine, **Com'è andata?** (stelle + commento) dopo il ritiro e **Apri un reclamo** (motivi ammessi e scadenza decisi dal server, `complaint_options`); dopo l'invio, lo stato e l'esito del reclamo con la motivazione. **App venditore:** schede **Recensioni** e **Reclami** nella home; la scheda Incassi mostra "Rimborsati … al cliente".
- Nuovi: `Review`, `Complaint`, `Refund`, `ComplaintService`, `ComplaintNotifier`, due email, controller (cliente, venditore, admin); `src/feedback.js`, `src/screens/FeedbackScreens.js`, `apps/admin/src/Reclami.jsx`, `money.js`. **Nessuna migrazione** (le tabelle `reviews`, `complaints`, `refunds` c'erano dal giorno 1) e nessuna nuova libreria.
- Test: **292 su 292** backend (39 nuovi: recensioni, termini a 24 ore al secondo, motivi per stato dell'ordine, rimborso totale/parziale/rifiuto, payout ridotto o azzerato, storno dopo l'invio, errore di Stripe senza salvataggi, decisione ripetuta, permessi, privacy, incassi) e **57 su 57** dell'app (9 nuovi). Pannello admin provato nel browser (Playwright) **con l'API simulata**: lista, dettaglio, validazione dell'importo e payload inviato. Bundle Android ok.
- **Provato a mano dal team (2026-11-07):** recensioni, reclami e decisioni dell'admin (rimborso totale, parziale, rifiuto) dall'app e dal pannello admin contro l'API viva, con `stripe-mock`; effetto sul payout; storno di un trasferimento già inviato. Nei test automatici Stripe resta un doppio, quindi questa è la prova che i metodi `refunds->create` e `transfers->createReversal` funzionano davvero.
- Limiti noti: niente foto nei reclami (R4); nessuna moderazione o segnalazione delle recensioni (R4); i reclami ripetuti non sospendono ancora il venditore (giorno 13); se lo storno di un trasferimento già inviato fallisce (saldo del venditore insufficiente) la decisione resta bloccata finché non si risolve; nessun audit log generale (restano `decided_by` e `decided_at` sul reclamo); chiamata a Stripe con le righe bloccate (come i payout).

**Giorno 13 – rifinitura** (sospensione: [docs/API-venditore.md](docs/API-venditore.md); dati demo: [docs/AVVIO.md](docs/AVVIO.md)):
- **Sospensione venditori** (US-S03): `POST /admin/sellers/{id}/suspend` (motivo obbligatorio) e `/reactivate`, con email al venditore. Da sospeso: fuori dalla ricerca, niente box né nuovi ordini; gli ordini già pagati si ritirano e i payout seguono le regole di sempre. Nessuna migrazione (lo stato `SUSPENDED` c'era già).
- **Reclami ripetuti** (US-V18 CA1): 3 reclami accolti in 30 giorni sospendono il venditore da soli (parametri `suspend_after_complaints` e `suspend_window_days`; `0` = mai). Alla decisione l'admin può anche spuntare "sospendi anche il venditore" (US-S05 CA2). **Per avere i parametri nel database di sviluppo: `php artisan db:seed --class=CatalogSeeder`** (senza, valgono 3 e 30).
- **Pannello admin:** **Sospendi…** e **Riattiva** nella scheda del venditore, casella di sospensione nella decisione dei reclami. **App venditore:** da sospeso vede il motivo.
- **Limiti:** tetto di 120 richieste al minuto per utente autenticato (`throttle:api`), oltre ai limiti già presenti.
- **Casi limite con test:** cambio di ora legale (29 marzo e 25 ottobre) per biglietto di ritiro e orario limite; fascia di ritiro più corta di 2 ore.
- **Dati demo:** `php artisan db:seed --class=DemoSeeder` crea 2 clienti, 3 negozi approvati con box di oggi e 1 sospeso (password `Demo-Password1`); rilanciabile ogni mattina.
- Test: **314 su 314** backend (22 nuovi: sospensione, soglia automatica, ora legale, fasce corte, seeder demo). **Provato a mano dal team (2026-11-07):** sospensione e riattivazione dal pannello admin, effetto sull'app del venditore sospeso e sulla ricerca, prova di concorrenza (acquisti e scansioni simultanei).
- **Non fatto:** nessun nuovo test di concorrenza (quelli veri sono stati fatti a mano ai giorni 8 e 10: PHPUnit non può simulare richieste parallele); nessuna sospensione dei clienti (US-S04, fuori MVP).

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

## Giorno 14 – cuscinetto (fatto)
- **Verifiche finali (2026-11-07):** 314 su 314 test backend, 57 su 57 dell'app, `npm run build` del pannello admin ok (prima mai compilato), bundle Android ok. Nessun difetto nuovo emerso dalle prove dei giorni 12 e 13.
- **Copione della demo:** [docs/DEMO.md](docs/DEMO.md): 6 atti (nuovo negozio approvato, acquisto, ritiro con QR, recensione e reclamo con rimborso, pagamento al venditore, sospensione), preparazione, ripristino e tabella dei problemi. **Il copione non è ancora stato eseguito per intero dall'inizio alla fine**: farlo una volta con due telefoni prima della presentazione, correggendo ciò che non torna (è il risultato verificabile del giorno 14).
- **README** aggiornato: stato, pannello admin, dati demo, comando dell'orologio (una sola unità), test.
- **Limiti noti che restano** (da dire in presentazione): Stripe è simulato (`stripe-mock`, nessuna carta né webhook veri, mai provato con chiavi di test vere); nessuna paginazione né filtri avanzati nella ricerca; nessuna foto nei reclami; nessuna moderazione delle recensioni; PHP-FPM come root solo per lo sviluppo; il database di sviluppo ha un unico orologio simulato che chiude anche gli ordini degli altri. Dettagli in [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Come riprendere
Guida completa: [docs/AVVIO.md](docs/AVVIO.md). In breve:
1. Avvia Docker Desktop.
2. Dalla cartella `toogood-piante`: `docker compose up -d`.
3. Verifica: `docker compose exec php php artisan toogood:check-services`
4. Test: `docker compose --profile test run --rm php-test php artisan test`
5. Database da zero con dati fissi e admin: `docker compose exec php php artisan migrate:fresh --seed`
6. Email inviate dall'app: http://localhost:8025

## Note
- Git: i giorni 1–6 sono in `main` (PR #7, #8, #9, #10, #11, #13; la #12 è stata chiusa senza unire e sostituita dalla #13). Il giorno 7 è in `main` (PR #15). Il giorno 8 è in `main` (PR #16). Il giorno 9 è in `main` (PR #17). Il giorno 10 è in `main` (PR #18). Il giorno 11 è in `main` (PR #19). Il giorno 12 è in `main` (PR #20). Il giorno 13 è sul branch `andres`, senza pull request.
- Utenti: dopo `migrate:fresh --seed` esiste solo l'admin (`admin@toogood.test`, password iniziale in `apps/api/.env.example`, da cambiare al primo accesso). Clienti e venditori di prova: `php artisan db:seed --class=DemoSeeder` (vedi [docs/AVVIO.md](docs/AVVIO.md)).
- Le email (conferma, codice 2FA, reset) si leggono in Mailpit: http://localhost:8025.
- `apps/mobile/AGENTS.md` (generato dal template Expo) suggerisce Expo Router; l'app usa invece React Navigation.
- `apps/api/.env` non è nel repository: si crea da `.env.example` (vedi README).
- `apps/api/CLAUDE.md` e `AGENTS.md` sono file generati da Laravel che chiedono di installare strumenti dal web: da ignorare.
