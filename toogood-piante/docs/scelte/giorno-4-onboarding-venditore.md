# Giorno 4 – Onboarding del venditore: perché queste scelte

Obiettivo del giorno: un venditore registrato inserisce i dati della sua attività, allega un documento, collega il conto di pagamento e passa da "in verifica" ad "approvato" grazie a un admin. Riferimenti: US-V02, V03, V04, S02 in `UserStory-v4.md`. Endpoint: [API-venditore.md](../API-venditore.md).

Ogni scelta: **cosa**, **perché**, **alternativa**, **dove**.

---

## 1. Il profilo venditore nasce dopo la registrazione, non durante

- **Cosa:** la registrazione crea solo l'account (giorno 2). I dati dell'attività si inviano con `PUT /seller/profile` al primo accesso; fino ad allora lo stato è `INCOMPLETE`.
- **Perché:** la registrazione resta breve e uguale per cliente e venditore, e l'email viene confermata prima di chiedere dati sensibili come la P.IVA. `INCOMPLETE` non è salvato nel database: è la risposta quando non esiste ancora un profilo, così non esistono righe a metà.
- **Dove:** [ProfileController.php](../../apps/api/app/Http/Controllers/Seller/ProfileController.php).

## 2. Una macchina a stati semplice e controllata dal server

- **Cosa:** `PENDING → APPROVED | REJECTED`, e da `REJECTED` si torna a `PENDING` solo con un reinvio esplicito. Si decide solo su richieste `PENDING`.
- **Perché:** lo stato decide cosa può fare il venditore (pubblicare o no), quindi non deve essere mai impostabile dal client: `verification_status`, `stripe_account_id` e `vat_check_status` non sono nei campi assegnabili in massa, e un test verifica che inviarli non abbia effetto. Due admin che decidono insieme sulla stessa richiesta ottengono 409 alla seconda.
- **Dopo il rifiuto** il venditore corregge e reinvia (US-V04 CA1) senza creare un secondo account.
- **Dopo l'approvazione** i dati non si modificano da questo flusso (`PROFILE_LOCKED`): cambiare P.IVA o indirizzo di un negozio già approvato aggirerebbe la verifica. La modifica controllata verrà gestita con la sospensione (giorno 13).
- **Dove:** [Seller.php](../../apps/api/app/Models/Seller.php).

## 3. Verifica P.IVA: si rifiuta ciò che è sbagliato, non ciò che non si può controllare

- **Cosa:** la P.IVA ha un controllo di formato (`IT` + 11 cifre) e poi un controllo presso il servizio VIES (in sviluppo simulato da WireMock). Tre esiti: valida → `VALID`; inesistente → errore 422 `VAT_INVALID`; servizio giù → il profilo si salva con esito `UNAVAILABLE`.
- **Perché:** se il servizio esterno è irraggiungibile non è colpa del venditore: bloccarlo per un guasto altrui sarebbe sbagliato. L'esito `UNAVAILABLE` resta visibile all'admin nella coda, che decide con più informazioni (US-S02: "l'esito dei controlli automatici"). Una P.IVA dichiarata inesistente invece si ferma subito, e **nulla** viene salvato.
- **Normalizzazione:** `it12 345 678 901`, `12345678901` e `IT12345678901` sono lo stesso valore. Così l'univocità (`unique`) non si aggira con spazi o minuscole.
- **Dove:** [VatChecker.php](../../apps/api/app/Services/Seller/VatChecker.php).

## 4. Geocoding all'inserimento, non alla ricerca

> **Aggiornamento (giorno 5):** il servizio non è più solo simulato. Di default si usa **Nominatim (OpenStreetMap)**, limitato all'Italia, che accetta qualsiasi indirizzo, città o paese; chiede uno User-Agent che identifichi l'app e al massimo una richiesta al secondo (rispettato: si cerca solo quando l'indirizzo cambia). La versione simulata (WireMock, solo Milano e Roma) resta con `GEOCODING_DRIVER=simulated` e nei test, che così non dipendono da internet.


- **Cosa:** l'indirizzo viene trasformato in latitudine, longitudine e città quando il venditore lo salva, e i valori restano nel database.
- **Perché:** la ricerca per distanza (giorno 7) deve essere veloce e non può chiamare un servizio esterno per ogni negozio a ogni ricerca. Si paga il costo una volta.
- **Indirizzo non trovato** → 422, il venditore lo corregge. **Servizio lento o non raggiungibile** → 503 `GEOCODING_UNAVAILABLE` con timeout di 5 secondi: l'utente può riprovare e la richiesta non resta appesa. Non si salva un negozio senza coordinate, perché non sarebbe trovabile.
- **Chiamate solo se i dati cambiano:** se il venditore modifica il telefono, P.IVA e indirizzo non vengono ricontrollati (un test lo verifica contando le chiamate).
- **Dove:** [Geocoder.php](../../apps/api/app/Services/Seller/Geocoder.php).

## 5. Orari validati nel server

- **Cosa:** chiusura dopo apertura; il ritiro sta dentro l'orario di apertura e la sua fine è dopo il suo inizio.
- **Perché:** gli orari di ritiro diventano le fasce delle box e l'ora limite per ordinare (2 ore prima della fine). Un orario incoerente romperebbe i calcoli dei giorni successivi; meglio fermarlo all'origine. L'app valida solo il formato.

## 6. Documenti: privati, controllati sul contenuto, con nome deciso dal server

- **Cosa:** PDF/JPG/PNG fino a 5 MB, massimo 5 per venditore, in un bucket S3 privato.
- **Il tipo si legge dal contenuto**, non dal nome: un file PHP rinominato `visura.pdf` viene rifiutato (provato sia nei test sia sullo stack vivo con una richiesta vera).
- **Il nome del file è generato dal server** (`seller-documents/{id}/{uuid}.pdf`): il nome inviato dal client si salva solo come etichetta, non entra mai nel percorso. Evita sovrascritture e percorsi manipolati.
- **Nessun indirizzo pubblico:** la chiave dell'oggetto non esce mai in una risposta e l'admin legge il documento tramite l'API (`GET /admin/sellers/{id}/documents/{docId}`, con `X-Content-Type-Options: nosniff`). Un venditore non può leggere i documenti nemmeno degli altri (403 dal ruolo, 404 se l'id non è suo). Rispetta US-S02 CA2: "documenti accessibili solo agli admin".
- **Alternativa scartata:** link temporanei firmati dello storage. In locale l'indirizzo dello storage è interno a Docker e il browser non lo raggiungerebbe, e comunque passare dall'API tiene i controlli di accesso in un solo punto.
- **Dove:** [DocumentController.php](../../apps/api/app/Http/Controllers/Seller/DocumentController.php).

## 7. Stripe Connect: l'account si crea una volta sola e "completo" significa "può ricevere trasferimenti"

- **Cosa:** `POST /seller/stripe/onboarding` crea un account Connect Express (se non c'è già) e restituisce il link dove il venditore completa i dati su Stripe. `POST /seller/stripe/sync` chiede a Stripe lo stato e salva `stripe_onboarded_at`.
- **Perché Express:** i dati di identità e bancari li raccoglie Stripe, non la nostra app: non conserviamo IBAN né documenti di identità del venditore.
- **Perché si considera completo quando la capacità `transfers` è attiva:** alla piattaforma serve una cosa sola, inviare al venditore il suo guadagno 24 ore dopo il ritiro (RB-11). È un criterio più preciso di "dati inviati". Con Stripe vero la capacità si attiva dopo che il venditore consegna i dati. Con `stripe-mock` risulta già attiva, quindi in sviluppo il percorso è provabile fino in fondo. Ho scelto questo criterio dopo aver visto che, con `details_submitted`, `stripe-mock` non permetteva mai di completare l'onboarding.
- **Perché una sincronizzazione esplicita e non un webhook:** `stripe-mock` non genera webhook, e il venditore torna comunque nell'app: l'app chiama `sync` quando torna in primo piano. I webhook veri (pagamenti) arrivano al giorno 8.
- **Servizio isolato:** tutto il codice Stripe di questa parte è in una classe, [ConnectService.php](../../apps/api/app/Services/Stripe/ConnectService.php), sostituita da una finta nei test. I test non dipendono da `stripe-mock` e girano uguali ovunque.
- **Non si può pubblicare finché non sono vere due cose:** `APPROVED` e Stripe collegato (`Seller::canPublish()`). Lo prevede US-V03.
- **Limite dichiarato:** il link di `stripe-mock` è un indirizzo di esempio, non una procedura reale. La prova con Stripe vero richiede chiavi di test.

## 8. Admin: rotte separate per ruolo e decisione motivata

- **Cosa:** un middleware `role:ADMIN` / `role:SELLER` protegge i due gruppi di rotte. L'admin con password iniziale non ha ancora accesso (`password.fresh`).
- **Perché:** il controllo del ruolo sta nelle rotte, non dentro ogni controller: non si può "dimenticare" in una nuova funzione. Un cliente che prova una rotta del venditore riceve 403.
- **Rifiuto con motivazione obbligatoria** (US-S02): il venditore la vede nell'app e nell'email e sa cosa correggere. **Non si approva senza documenti:** l'admin deve avere qualcosa da consultare.
- **Email all'esito** (US-V04): l'email di approvazione o rifiuto è inviata subito dopo la decisione.
- **Dove:** [SellerVerificationController.php](../../apps/api/app/Http/Controllers/Admin/SellerVerificationController.php), [EnsureUserHasRole.php](../../apps/api/app/Http/Middleware/EnsureUserHasRole.php).

## 9. App mobile: una schermata che racconta a che punto sei

- **Cosa:** `SellerHome` legge `GET /seller` e mostra uno stato (da completare, in verifica, approvata, rifiutata con motivo, sospesa), il riepilogo dei dati, il conto di pagamento e un messaggio "tutto pronto" solo quando `can_publish` è vero. `SellerProfileScreen` è il modulo dei dati con i documenti.
- **Perché:** il server decide, l'app mostra. L'app non ricalcola mai se il venditore può pubblicare: legge `can_publish`. Meno logica duplicata, nessuna possibilità che app e server siano in disaccordo.
- **Si aggiorna da sola:** ogni volta che la schermata torna visibile (`useFocusEffect`) e quando l'app torna in primo piano dopo Stripe (`AppState`), che è il momento in cui conviene chiedere a Stripe come è andata.
- **Documenti:** si scelgono con il selettore di sistema (`expo-document-picker`), limitato a PDF/JPG/PNG. Il selettore non chiede permessi su fotocamera o galleria.
- **Invio dei file con il fetch di Expo SDK 58:** il vecchio modo di allegare un file (`{ uri, name, type }`) non è più accettato e l'invio falliva prima ancora di partire, mostrando "Impossibile contattare il server". Ora il file si legge con `expo-file-system` e si allega come parte con i suoi byte (`api.js`). Il difetto è emerso solo provando su un telefono vero: i test e la prova sullo stack usano un client diverso.
- **Limite di PHP allineato alla regola:** il controller accetta fino a 5 MB ma PHP era fermo a 2 MB di default. Ora `docker/php/uploads.ini` imposta 6 MB (provato: 4 MB accettato, 5,6 MB rifiutato con 422).
- **Errori in italiano:** nuovi codici e campi aggiunti a `errors.js` (stessa scelta del giorno 3: l'app decide i testi).
- **Piccola pulizia:** l'hook `useAction` (stato "in corso" + errore tradotto) è passato in un file proprio per essere usato anche dalle nuove schermate.

## 10. Come lo abbiamo verificato

- **98 test automatici su 98** (37 nuovi sull'onboarding e sulla verifica admin: stati, P.IVA, geocoding, orari, documenti, Stripe, ruoli, percorso completo).
- **Prova sullo stack vivo** con Mailpit, WireMock, S3Mock e stripe-mock: registrazione → conferma → accesso con codice → dati → documento (anche un file falso rifiutato) → Stripe → coda admin → lettura del documento → rifiuto → reinvio → approvazione → email ricevute. Risultato finale: `can_publish = true`.
- **App mobile:** il bundle Android compila (`expo export`) e `expo-doctor` passa 19 controlli su 20; il ventesimo segnala solo che esiste una patch più recente del pacchetto `expo` (58.0.22 contro 58.0.21), non legata a questo lavoro.
- **Non verificato:** le nuove schermate non sono state provate su un telefono o emulatore (in questo PC non c'è Node né un emulatore), né con un Stripe vero.
