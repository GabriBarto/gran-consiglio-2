# Come avviare TooGood Piante

Guida passo passo, dal PC spento alla prova completa dell'app. I comandi vanno lanciati in un terminale (PowerShell va bene) dalla cartella `toogood-piante`, tranne dove indicato.

## Cosa serve

| Strumento | Perché | Controllo |
|---|---|---|
| **Docker Desktop** (acceso) | Fa girare API, database e simulatori | `docker --version` |
| **Node 24** | Serve per l'app mobile e per il pannello admin | `node --version` |
| **Expo Go** sul telefono (Android o iOS), oppure un emulatore | Per aprire l'app | – |

PHP e Composer **non** vanno installati: girano nei container.
Il telefono e il PC devono essere sulla **stessa rete Wi-Fi**.

---

## A. Primo avvio (una volta sola)

### 1. File di configurazione

Se manca `apps/api/.env`, crealo dal modello:

```bash
cp apps/api/.env.example apps/api/.env
```
(In PowerShell: `Copy-Item apps/api/.env.example apps/api/.env`)

### 2. Avvia i container

```bash
docker compose up -d --build
```

### 3. Installa le dipendenze, genera la chiave, crea il database

```bash
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
```

`--seed` carica i dati fissi (tipi di box, categorie, parametri) e crea l'**utente admin**.

### 4. Controlla che tutto risponda

```bash
docker compose exec php php artisan toogood:check-services
```

Se qualche servizio non risponde, aspetta mezzo minuto e ripeti: MySQL ci mette un po' ad avviarsi.

### 5. Installa l'app mobile

```bash
cd apps/mobile
npm install
```

---

## B. Avvii successivi (ogni volta)

```bash
docker compose up -d
```

Se dopo un riavvio l'API dà errore 502 (può succedere con versioni vecchie della configurazione), riavvia nginx con `docker compose restart nginx`.

Poi l'app mobile:

```bash
cd apps/mobile
npx expo start
```

Si apre un QR code nel terminale:
- **Telefono vero:** apri Expo Go e scansiona il QR (su iPhone con la fotocamera).
- **Emulatore Android:** premi `a` nel terminale di Expo.
- **Simulatore iOS (solo Mac):** premi `i`.

L'app trova da sola l'indirizzo dell'API. Se non ci riesce, forzalo (sostituisci con l'IP del PC, vedi sotto):

```powershell
$env:EXPO_PUBLIC_API_URL = "http://192.168.1.50:8080/api"
npx expo start --clear
```

### Se usi un telefono vero: serve l'IP del PC

1. Trova l'IP del PC:
   ```powershell
   ipconfig
   ```
   Cerca "Indirizzo IPv4" della scheda Wi-Fi (es. `192.168.1.50`).
2. In `apps/api/.env` cambia la riga:
   ```
   APP_URL=http://192.168.1.50:8080
   ```
3. Riavvia i container PHP per rileggere la configurazione:
   ```bash
   docker compose restart php queue scheduler nginx
   ```
4. Se il telefono non raggiunge il PC, il **firewall di Windows** sta bloccando la porta 8080 (API) o 8081 (Expo): consenti Docker e Node sulla rete privata.

Con l'emulatore Android o l'app aperta solo sul PC, `APP_URL=http://localhost:8080` va bene.

---

## C. Le email: dove andare

L'app non manda email vere. **Tutte** le email finiscono in Mailpit, una casella di posta di prova.

> **Apri http://localhost:8025 nel browser del PC.**

Lì trovi (nell'ordine in cui le provochi):

| Quando | Email | Cosa farne |
|---|---|---|
| Dopo la **registrazione** | "Conferma il tuo indirizzo email" | Clicca il **link di conferma**. Si apre una pagina che dice che l'email è confermata. Il link vale 24 ore. |
| A ogni **accesso**, dopo email+password | Codice a 6 cifre | Scrivilo nell'app nella schermata del codice. Vale 10 minuti, 5 tentativi. |
| **Password dimenticata** | Codice a 6 cifre | Scrivilo nell'app insieme alla nuova password. Vale 30 minuti. |

### Il link di conferma non si apre?

- Il link usa `APP_URL` di `apps/api/.env`. Se lo clicchi **dal PC** con `APP_URL=http://localhost:8080`, funziona.
- Se lo apri **dal telefono**, `localhost` è il telefono stesso e non funziona: usa l'IP del PC (vedi sopra) oppure apri Mailpit dal PC e clicca il link lì.
- Se è scaduto, nell'app, schermata "Controlla la posta" → **Reinvia**, poi torna su Mailpit.

---

## D. Prova completa (5 minuti)

1. `docker compose up -d`, poi `npx expo start` in `apps/mobile`, apri l'app.
2. **Registrati** come cliente o venditore (password di almeno 10 caratteri con lettere e numeri).
3. Vai su **http://localhost:8025**, apri l'email e clicca il link di conferma.
4. Nell'app, torna indietro e fai **accesso** con email e password.
5. Torna su Mailpit, copia il **codice a 6 cifre** e inseriscilo nell'app.
6. Sei dentro: vedi la home del tuo ruolo (per ora un segnaposto con "Esci").

### Accesso come admin

L'admin esiste solo dopo `migrate --seed`. Email e password iniziale sono nel file `apps/api/.env.example` (`ADMIN_EMAIL`, `ADMIN_INITIAL_PASSWORD`). Dopo il codice via email (Mailpit) l'app chiede **subito di cambiare la password**: è voluto.

### Prova come venditore (giorno 4)

Dopo l'accesso come **venditore** l'app mostra "Il tuo negozio" con stato "Da completare".

1. Tocca **Inserisci i dati dell'attività** e compila (valori che il simulatore accetta):
   - Partita IVA: `IT` + 11 cifre che iniziano con 1–8, per esempio `IT12345678901`; **ogni venditore ne vuole una diversa** (`IT22345678901`, `IT33345678901`…), perché sono univoche. Quelle che iniziano con 0 o 9 danno "non valida"; `IT99999999999` simula il servizio giù
   - Indirizzo: **qualsiasi indirizzo, città o paese d'Italia**, scritto **via, numero, città** con la virgola prima della città, per esempio `Via Rizzoli 5, Bologna` o `Via Rossini 10, Pesaro`; oppure solo il nome di una città, come `Pesaro`. Il controllo usa OpenStreetMap (serve internet) e verifica che l'indirizzo esista **proprio in quella città**: un civico che non c'è lì viene rifiutato, indicando dove si trova quello più simile
   - Orari: il ritiro deve stare dentro gli orari di apertura (es. aperto 08:00–19:00, ritiro 17:00–19:00)
2. **Salva e continua**, poi **Allega un documento** (un PDF, JPG o PNG fino a 5 MB).
3. Torna al negozio: lo stato è **In verifica**. Tocca **Collega il conto di pagamento** (con il simulatore Stripe il link è solo un esempio), poi **Ho finito: controlla**: compare "Collegato ✓".
4. **Approva il venditore dal pannello web** (sezione "Pannello admin web" qui sotto): apri http://localhost:5173, accedi come admin, scegli la richiesta in attesa e premi **Approva**.
5. Nell'app tocca **Aggiorna stato**: "Approvata" e "Tutto pronto". L'email dell'esito è in Mailpit.

### Prova gli ordini e i pagamenti (giorno 8, solo API: l'app è il giorno 9)

Nell'app non c'è ancora il carrello. Si prova con PowerShell o con i test automatici.

**Test automatici** (non toccano il tuo database):

```bash
docker compose --profile test run --rm php-test php artisan test --filter="CheckoutTest|StripeWebhookTest|ReservationReleaseTest"
```

**A mano, da PowerShell**, con un negozio approvato, con conto collegato e una box di oggi (vedi "Prova le box") e un account **cliente**. Servono il token del cliente (accesso con codice da Mailpit) e l'`id` della box (`GET /api/shops/{id}` o `GET /api/seller/boxes`):

```powershell
$api = "http://localhost:8080/api"
$json = @{ Accept = "application/json" }
$c = Invoke-RestMethod -Method Post -Uri "$api/auth/login" -Headers $json -ContentType "application/json" -Body (@{email="IL-TUO-CLIENTE@example.com"; password=(Read-Host "Password")} | ConvertTo-Json)
$t = Invoke-RestMethod -Method Post -Uri "$api/auth/login/verify" -Headers $json -ContentType "application/json" -Body (@{challenge_id=$c.challenge_id; code=(Read-Host "Codice da Mailpit")} | ConvertTo-Json)
$auth = @{ Accept = "application/json"; Authorization = "Bearer $($t.access_token)" }

# 1. Ordine: le box restano tue per 10 minuti (sostituisci 12 con l'id della box)
$o = Invoke-RestMethod -Method Post -Uri "$api/orders" -Headers $auth -ContentType "application/json" -Body '{"items":[{"box_id":12,"quantity":1}],"accept_no_refund":true}'
$o | Select-Object number, status, total_cents, reserved_until

# 2. Pagamento di prova: passa dallo stesso codice del webhook vero
Invoke-RestMethod -Method Post -Uri "$api/orders/$($o.id)/simulate-payment" -Headers $auth | Select-Object number, status, paid_at
```

Poi apri **Mailpit** (http://localhost:8025): trovi "Ordine TG-… confermato" (al cliente) e "Nuovo ordine TG-…" (al venditore). Per vedere la **scadenza** (box che tornano disponibili): crea l'ordine, **non** pagare, e lancia

```bash
docker compose exec php php artisan clock advance 11m
docker compose exec php php artisan reservations:release
docker compose exec php php artisan clock reset
```

L'ordine diventa `EXPIRED` e lo stock torna com'era. Il token dura 15 minuti.

### Prova la ricerca dei negozi (giorno 7)

Serve almeno un negozio **approvato, con il conto collegato e una box di oggi** (vedi la prova delle box qui sotto). L'app ora si apre sull'elenco dei negozi, anche senza accesso.

1. All'avvio l'app chiede il **permesso di posizione**: con "Consenti" i negozi sono ordinati per distanza; con "Non consentire" si cerca solo per città o nome. Sull'emulatore la posizione potrebbe non esserci: si imposta dalle opzioni dell'emulatore, o si cerca per città.
2. Prova la ricerca: **nome** (`vivaio`), **città** (`bologna`, anche senza accenti o maiuscole) e l'interruttore **"Mostra anche i negozi senza box oggi"**.
3. Tocca un negozio: **scheda** con orari, fascia di ritiro di oggi, orario limite d'ordine, recensioni e box con prezzo e "risparmi almeno X €". Le box esaurite compaiono in fondo, segnate come tali.
4. **Da visitatore** (senza accesso) tocca **Accedi per acquistare** su una box: l'app ricorda quella box e apre l'accesso. Registrati come **cliente**, conferma l'email da Mailpit, accedi con il codice: l'app ti riporta alla **scheda del negozio con quella box evidenziata** ("Bentornato!…"). Se nel frattempo la box è esaurita o l'orario limite è passato, vedi un messaggio rosso.
5. **Da cliente** vedi le stesse schede; il pulsante di acquisto arriva col carrello (giorno 8).

Per controllare dal PC (senza token): `http://localhost:8080/api/shops?lat=44.4938&lng=11.3426` (vedi [API-negozi.md](API-negozi.md)). Dopo il pull serve `cd apps/mobile && npm install` (nuova libreria della posizione).

### Prova le box (giorno 6)

Serve un venditore **approvato dall'admin** e con il **conto di pagamento collegato** (nell'app: "Collega il conto di pagamento", poi "Ho finito: controlla"). Allora nella home del venditore compare **Box di oggi**.

1. Tocca **Nuova box**, scegli tipologia, categoria e caratteristiche; la descrizione del contenuto è facoltativa.
2. Inserisci il **valore di listino** (es. `20,00`) e uno **sconto** tra 60% e 70%. Il **Riepilogo del prezzo** mostra subito listino, sconto, prezzo, tariffa (1,50 €) e il tuo guadagno. Esempio: 20,00 € con sconto 65% → prezzo 7,00 €, guadagno 5,50 €.
3. Per vedere l'avviso: listino `3,00` e sconto 70% → prezzo 0,90 €, guadagno **negativo**. Il riepilogo diventa rosso e il pulsante resta spento finché non spunti "Ho capito, pubblica comunque".
4. **Pubblica la box**: la ritrovi nell'elenco con prezzo, disponibili e guadagno. Toccala per **modificarla** (descrizione, tipologia…; la quantità può solo diminuire) o **eliminarla**.

Per controllare dal PC: `GET /api/seller/boxes` (vedi [API-box.md](API-box.md)). I test automatici dell'app (formula del prezzo): `cd apps/mobile && npm test`.

### Pannello admin web (giorno 5)

Sito React/Vite per l'admin: coda delle verifiche dei venditori. Serve l'API già avviata.

```bash
cd apps/admin
npm install      # solo la prima volta
npm run dev
```

Apri http://localhost:5173 (la porta è fissa: l'API accetta richieste solo da lì).

1. Accedi con l'email e la password dell'admin (`apps/api/.env.example`), poi inserisci il codice a 6 cifre che trovi in Mailpit.
2. Al primo accesso il pannello chiede di cambiare la password: è voluto.
3. Nella scheda **In attesa** scegli una richiesta: vedi dati, esito del controllo P.IVA, stato del conto di pagamento e documenti (si aprono in una nuova scheda).
4. **Approva** oppure **Rifiuta…** con un motivo di almeno 5 caratteri. Il venditore riceve l'email (Mailpit) e vede il nuovo stato nell'app con **Aggiorna stato**.

Se il pannello dice "Impossibile contattare il server", controlla che Docker sia acceso e che l'API risponda su http://localhost:8080.

Dopo aver scaricato questa versione del codice, aggiorna il database:

```bash
docker compose exec php php artisan migrate
```

---

## E. Comandi utili

| Cosa | Comando |
|---|---|
| Stato dei container | `docker compose ps` |
| Log dell'API | `docker compose logs -f php` |
| Test automatici (database separato) | `docker compose --profile test run --rm php-test php artisan test` |
| Database da zero con dati fissi e admin | `docker compose exec php php artisan migrate:fresh --seed` |
| Verifica dei servizi | `docker compose exec php php artisan toogood:check-services` |
| Interfaccia web del database (Adminer, http://localhost:8081) | `docker compose --profile tools up -d` |
| Orologio simulato: ora attuale / avanti di 24 ore / azzera | `docker compose exec php php artisan clock now` · `clock advance 24h` · `clock reset` |
| Spegnere tutto (i dati restano) | `docker compose down` |
| Spegnere e **cancellare** il database | `docker compose down -v` |

Attenzione: Adminer usa la porta **8081**, la stessa di Expo. Se avvii Adminer, Expo propone un'altra porta: accettala.

## Indirizzi

| Cosa | Indirizzo |
|---|---|
| API | http://localhost:8080/api |
| **Email (Mailpit)** | **http://localhost:8025** |
| Adminer (solo con `--profile tools`) | http://localhost:8081 |
| MySQL | `localhost:3307`, utente `toogood`, database `toogood` |

## Se qualcosa non va

| Sintomo | Soluzione |
|---|---|
| Errore **502** dall'API | `docker compose restart nginx` (la configurazione attuale dovrebbe evitarlo anche dopo un riavvio di `php`) |
| Le chiamate admin rispondono **403** | L'admin ha ancora la password iniziale: va cambiata (passo 4 della prova venditore) |
| Il caricamento del documento dà "Impossibile contattare il server" | Rifai `npm install` in `apps/mobile` e riavvia con `npx expo start --clear`; il motivo vero compare nel terminale di Expo |
| L'app dice "Impossibile contattare il server" | Container spenti (`docker compose ps`), telefono su un'altra rete, firewall, oppure indirizzo sbagliato: imposta `EXPO_PUBLIC_API_URL` |
| Il codice 2FA non arriva | Guarda Mailpit; il login ha un limite di 5 tentativi al minuto |
| "Troppe richieste" | Aspetta un minuto e riprova |
| `php artisan test` dal servizio `php` rifiutato | È una protezione: usa il comando dei test con `php-test` (tabella sopra) |
| Docker non parte / porta occupata | Chiudi il programma che usa la porta (3307, 8025, 8080) o riavvia Docker Desktop |
