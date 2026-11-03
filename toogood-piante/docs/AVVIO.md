# Come avviare TooGood Piante

Guida passo passo, dal PC spento alla prova completa dell'app. I comandi vanno lanciati in un terminale (PowerShell va bene) dalla cartella `toogood-piante`, tranne dove indicato.

## Cosa serve

| Strumento | Perché | Controllo |
|---|---|---|
| **Docker Desktop** (acceso) | Fa girare API, database e simulatori | `docker --version` |
| **Node 24** | Serve per l'app mobile | `node --version` |
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

Se hai riavviato il servizio `php`, riavvia anche nginx (altrimenti l'API dà errore 502):

```bash
docker compose restart nginx
```

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
| Errore **502** dall'API | `docker compose restart nginx` |
| L'app dice "Impossibile contattare il server" | Container spenti (`docker compose ps`), telefono su un'altra rete, firewall, oppure indirizzo sbagliato: imposta `EXPO_PUBLIC_API_URL` |
| Il codice 2FA non arriva | Guarda Mailpit; il login ha un limite di 5 tentativi al minuto |
| "Troppe richieste" | Aspetta un minuto e riprova |
| `php artisan test` dal servizio `php` rifiutato | È una protezione: usa il comando dei test con `php-test` (tabella sopra) |
| Docker non parte / porta occupata | Chiudi il programma che usa la porta (3307, 8025, 8080) o riavvia Docker Desktop |
