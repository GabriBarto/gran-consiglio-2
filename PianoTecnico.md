# Piano tecnico – App "Too Good To Go" per piante (versione 3)

Base: [UserStory-v4.md](UserStory-v4.md). Nomi tra parentesi (RB-xx, US-xx) rimandano a quel documento.

**Obiettivo:** prototipo funzionante per un progetto di studio/esame, con Stripe in modalità test e **tutti i servizi esterni simulati in Docker**.
**Linguaggi:** **PHP** (backend), **JavaScript** (pannello admin e codice condiviso lato client), **React Native** (app mobile, con Expo). **Database:** **MySQL**.

---

## 1. Scelte tecnologiche

| Livello | Scelta | Note |
|---|---|---|
| Backend / API | **PHP 8.4 + Laravel** | Laravel offre già ciò che serve: migrazioni, validazione, code, scheduler, invio email, rate limiting, storage compatibile S3 e test. |
| App mobile | **React Native con Expo, in JavaScript** | Un'unica app con due ruoli (cliente / venditore). |
| Pannello admin | **React + Vite, in JavaScript (JSX)** | Sito web separato, solo super admin. |
| Database | **MySQL 8 (InnoDB)** | Transazioni e locking riga per lo stock; tipi spaziali per la ricerca per distanza. |
| Accesso al DB | **Eloquent / Query Builder** di Laravel, con SQL diretto per le query geografiche | |
| Autenticazione API | **Laravel Sanctum** (token) con access token breve e refresh token ruotabile | Il refresh e la 2FA via email sono logica nostra sopra Sanctum. |
| Validazione | **Form Request di Laravel** (fonte di verità) + controlli leggeri nei client JS | Il server ricalcola e valida sempre tutto. |
| Contratto API | **OpenAPI** (generato dal backend) | Evita che app e admin si disallineino rispetto all'API. |
| Job e pianificazione | **Laravel Queue** (driver `database`) + **Scheduler** | Nessun Redis. I job prendono le righe con `lockForUpdate()->skipLocked()`. |
| Password | **argon2id** (supportato da Laravel) | |
| Pagamenti | **Stripe Connect, modalità test**, tramite SDK `stripe-php` | Vedi sezione 7. |
| Email | SMTP verso **Mailpit** in sviluppo e test | In produzione basterebbe cambiare le variabili d'ambiente. |
| File | **S3Mock** (compatibile S3) tramite il filesystem di Laravel | Documenti P.IVA e foto dei reclami. |
| Orologio | Servizio `Clock` che avvolge Carbon | Permette di simulare il passare del tempo nei test e nelle demo. |

**Struttura del repository:**
```
apps/api        Laravel (PHP)
apps/mobile     app Expo / React Native (JavaScript)
apps/admin      pannello admin React/Vite (JavaScript)
contracts/      specifica OpenAPI e casi di test condivisi (es. formula del prezzo in JSON)
docker/         compose, Dockerfile, configurazioni dei simulatori
```

La **formula del prezzo** vive nel backend (autorevole). L'app la ricalcola solo per l'anteprima al venditore, e un file di **casi di test condivisi** (`contracts/`) viene eseguito sia dai test PHP sia dai test JavaScript, così le due implementazioni non possono divergere.

## 2. Architettura

```
App React Native (cliente + venditore)    Admin web (React)
            \                                  /
             \--------- API Laravel -----------/
                  |        |         |
               MySQL    S3Mock    SMTP (Mailpit)
                  |
        Stripe (modalità test / stripe-mock),
        geocoding e verifica P.IVA (simulati)
```

- **Fuso orario:** date salvate in **UTC**; "oggi" e le fasce di ritiro sono calcolati in `Europe/Rome`.
- **Moduli del backend** (cartelle per dominio): `Auth`, `Users`, `Sellers`, `Offers`, `Search`, `Orders`, `Payments`, `Pickup`, `Payouts`, `Reviews`, `Complaints`, `Admin`, `Config`, `Gdpr`.

## 3. Modello dati (entità principali)

| Entità | Campi chiave |
|---|---|
| `User` | id, email (unica), username (unico), password_hash, role (CUSTOMER/SELLER/ADMIN), status (ACTIVE/SUSPENDED/DELETED), email_verified_at, anonymized_at |
| `LoginChallenge` | user_id, hash del codice 2FA, expires_at, tentativi, usato |
| `RefreshToken` | user_id, hash, dispositivo, expires_at, revoked_at |
| `Seller` | user_id, ragione_sociale, partita_iva, telefono, indirizzo, `latitude`, `longitude` (decimali), stripe_account_id, verification_status (PENDING/APPROVED/REJECTED/SUSPENDED), motivazione |
| `SellerDocument` | seller_id, chiave oggetto in S3Mock, tipo, caricato il |
| `PickupSchedule` | seller_id, giorno settimana, apertura, chiusura, ritiro_da, ritiro_a |
| `ScheduleException` | seller_id, data, chiuso / orari alternativi |
| `BoxType`, `Category`, `PlantAttribute` | gestiti dall'admin (interno/esterno, tossicità, ecc.) |
| `BoxTemplate` | modello ricorrente del venditore |
| `BoxOffer` | seller_id, **data**, tipo, categoria, attributi (JSON), descrizione, valore_listino, sconto_pct, prezzo, tariffa_applicata (snapshot), stock_totale, stock_disponibile, stato |
| `Order` | customer_id, seller_id, data, stato, totale, tariffa_totale, payment_intent_id, accettazione_no_rimborso_at |
| `OrderItem` | order_id, offer_id, quantità, prezzo_unitario, tariffa_unitaria |
| `StockReservation` | order_id, offer_id, quantità, expires_at |
| `PickupToken` | order_id, jti, codice_alfanumerico, valido_da/a, usato_at, scansionato_da |
| `Payout` | order_id, importo = max(0, prezzo − tariffa), scheduled_at, stato, stripe_transfer_id |
| `Review` | order_id (unica per ordine), voto, commento, stato |
| `Complaint` / `ComplaintPhoto` | order_id, caso (a–d), descrizione, stato, decisione, motivazione, importo_rimborso; foto su S3Mock |
| `Refund` | order_id, complaint_id, importo, stripe_refund_id |
| `StripeEvent` | id evento Stripe (unico), tipo, elaborato il (per l'idempotenza dei webhook) |
| `ConfigParam` | tariffa, sconto_min/max, ore_limite_ordine, ritardo_payout, termine_reclamo |
| `AuditLog`, `EmailLog` | attore/azione/entità/data; destinatario/tipo/stato |

Regole di dato importanti:
- **Snapshot dei parametri:** prezzo e tariffa si copiano su `BoxOffer`/`OrderItem` al momento della vendita (US-S07 CA1).
- **Importi in centesimi interi** (`*_cents`) e sconto intero in percentuale: niente numeri decimali per i soldi.
- **Vincoli DB:** `CHECK (stock_disponibile >= 0)` (MySQL ≥ 8.0.16), `sconto_pct` tra i valori di configurazione, `Review.order_id` unica.
- **Indici:** indice su (`latitude`, `longitude`) di `Seller`; indice su (`BoxOffer.data`, `seller_id`, `stato`); indice su `StockReservation.expires_at`.
- **Ricerca per distanza:** `ST_Distance_Sphere(POINT(longitude, latitude), POINT(:lng, :lat))` con prefiltro per riquadro sulle colonne indicizzate (scelta: coordinate decimali al posto del tipo spaziale, per evitare le particolarità dell'ordine delle coordinate con SRID 4326).
- **Cancellazione account:** si anonimizzano i campi personali di `User`, non si eliminano gli ordini (US-C16).

## 4. Macchine a stati

**Ordine**
```
PENDING_PAYMENT → PAID → PICKED_UP
        │           │
        │           ├→ NOT_PICKED_UP        (fine fascia di ritiro, nessuna scansione)
        │           └→ IN_COMPLAINT → REFUNDED_FULL | REFUNDED_PARTIAL | COMPLAINT_REJECTED
        └→ EXPIRED / PAYMENT_FAILED           (stock rilasciato)
```

**Payout:** `SCHEDULED` → `HELD` (reclamo aperto) → `SENT`; oppure `SKIPPED` (importo 0 o rimborso totale prima dell'invio) o `REVERSED` (revoca dopo l'invio).

**Verifica venditore:** `PENDING` → `APPROVED` | `REJECTED` → (nuovo invio) `PENDING`; `APPROVED` ↔ `SUSPENDED`.

## 5. Flussi critici

### 5.1 Ordine e pagamento (US-C04…C08, US-X02)
1. **Carrello** lato app; il server ricalcola sempre prezzi e disponibilità.
2. **Checkout**, in una transazione per ogni riga:
   ```sql
   UPDATE box_offers SET stock_disponibile = stock_disponibile - ?
   WHERE id = ? AND stock_disponibile >= ? AND stato = 'ATTIVA';
   ```
   Se le righe modificate sono 0 lo stock è finito: nessun addebito. Si crea una `StockReservation` con scadenza 10 minuti e un `Order` in `PENDING_PAYMENT`.
3. Controlli prima della prenotazione: orario limite (fine fascia − 2 ore), 2FA attiva, casella "nessun rimborso" accettata.
4. **PaymentIntent Stripe** con `transfer_group` = id ordine. Incasso alla piattaforma (RB-11).
5. **Webhook `payment_intent.succeeded`** (firma verificata con `Webhook::constructEvent`, idempotente tramite `StripeEvent`): ordine → `PAID`, creazione `PickupToken` e `Payout` programmato, email a cliente e venditore.
6. **Scadenza o fallimento:** un comando pianificato ogni minuto rilascia le prenotazioni scadute, riporta lo stock e annulla il PaymentIntent.
7. **Pagamento tardivo:** se arriva un `succeeded` per una prenotazione già rilasciata, si rimborsa automaticamente e si avvisa il cliente.

### 5.2 Ritiro con QR (US-C09, US-V15)
- QR = token firmato (JWT breve) con `order_id` e `jti`, valido nella fascia di ritiro del giorno, più codice alfanumerico alternativo.
- `POST /pickup/scan`: il server verifica firma, negozio, giorno e fascia, poi segna l'uso con aggiornamento atomico (`WHERE usato_at IS NULL`). Una seconda scansione risponde "già usato".
- L'app del cliente salva il QR in locale una volta caricato.
- Ordine → `PICKED_UP`; si abilita la recensione.

### 5.3 Payout ritardato (RB-11, US-X01)
- Trasferimento programmato a **fine fascia di ritiro + 24 ore** (parametro di configurazione, in demo riducibile a pochi minuti).
- All'esecuzione: reclamo aperto → `HELD`; altrimenti `Transfer` verso l'account del venditore per `max(0, prezzo − tariffa)`. Importo 0 → `SKIPPED`.

### 5.4 Reclamo e rimborso (US-C14, US-S05)
- Apertura entro 24 ore dal ritiro (caso (a): dalla fine fascia); il termine è controllato dal server.
- Foto su S3Mock con URL temporanei, visibili solo all'admin.
- Decisione admin → rimborso Stripe (totale/parziale), payout ridotto o annullato; se già inviato, `Transfer Reversal`. Tutto nell'audit log.

### 5.5 Autenticazione con 2FA via email (RB-17, US-A01…A07)
1. Registrazione → email di verifica (link monouso).
2. Login: password → codice a 6 cifre via email → verifica → access token (15 minuti) + refresh token ruotabile.
3. Codice salvato come hash, valido 10 minuti, massimo 5 tentativi, rate limit per utente e per IP (rate limiter di Laravel).
4. Il codice è richiesto di nuovo su un nuovo dispositivo. Il reset password revoca tutti i token.

## 6. Job pianificati (Laravel Scheduler + Queue)

| Comando / job | Frequenza | Funzione |
|---|---|---|
| `reservations:release` | ogni minuto | rilascia stock e annulla PaymentIntent scaduti |
| `orders:mark-not-picked-up` | ogni 5 minuti | ordini `PAID` oltre la fine fascia → `NOT_PICKED_UP` |
| `offers:close-day` | giornaliero | chiude le offerte di ieri e genera quelle da modelli ricorrenti |
| `payouts:run` | ogni 10 minuti | esegue i payout con `scheduled_at` raggiunto |
| Invio email | in coda | email come job in coda, con ripetizioni in caso di errore |
| `auth:cleanup` | giornaliero | elimina codici 2FA e token scaduti |

Tutti usano il servizio `Clock` (non `now()` diretto), così i test possono far avanzare il tempo. Con più worker si evita l'esecuzione doppia con `lockForUpdate()->skipLocked()`.

## 7. Ambienti Docker per test e simulazioni

Un unico `docker compose` con due profili: **dev** (sviluppo e demo) e **test** (automatici, isolati, database in `tmpfs`).

| Servizio | Immagine | Che cosa fa |
|---|---|---|
| `php` | immagine del progetto (PHP-FPM 8.4 + estensioni) | Esegue Laravel. |
| `nginx` | `nginx` | Web server davanti a PHP-FPM. |
| `queue` | stessa immagine di `php` | `php artisan queue:work` per email e job. |
| `scheduler` | stessa immagine di `php` | `php artisan schedule:work` per i job pianificati. |
| `mysql` | `mysql:8` | Database (un'istanza `mysql-test` separata per i test). |
| `mailpit` | `axllent/mailpit` | **Email e notifiche** (le notifiche sono solo email): SMTP con pagina web e API per leggere i messaggi, anche dai test (codici 2FA, link di verifica, conferme d'ordine). |
| `stripe-mock` | `stripe/stripe-mock` | **Pagamenti nei test automatici:** API Stripe locale generata dalle specifiche ufficiali (PaymentIntent, Transfer, Refund, account Connect). Risponde con dati fissi, senza stato né webhook. |
| `stripe-cli` | `stripe/stripe-cli` | **Pagamenti nelle demo:** inoltra i webhook della modalità test Stripe all'API locale (servono le chiavi di test). |
| `s3mock` | `adobe/s3mock` | **Storage file** compatibile S3, in memoria (i file spariscono al riavvio). S3Mock non è più scaricabile dai registri pubblici. |
| `wiremock` | `wiremock/wiremock` | **Servizi HTTP esterni:** geocoding e verifica P.IVA con risposte predefinite (indirizzo valido, non trovato, P.IVA inesistente, timeout). |
| `admin` | immagine Node | Pannello admin in sviluppo. |
| `adminer` *(facoltativo)* | `adminer` | Interfaccia web per guardare il database. |

Note e limiti:
- **L'app React Native non gira in Docker:** si avvia sul PC con emulatore o telefono (Expo Go) e punta all'indirizzo di `nginx`.
- **`stripe-mock` non genera eventi:** nei test dei webhook l'API riceve **eventi firmati costruiti dal test**, così si prova anche la verifica della firma. L'onboarding Connect "vero" richiede la modalità test di Stripe e `stripe-cli`; nei test automatici si simula.
- **Orologio:** `CLOCK_MODE=fake` (solo dev/test) e un comando/endpoint riservato per far avanzare il tempo (fine fascia, +24 ore, scadenza prenotazione) senza aspettare.
- **Dati di prova:** seeder Laravel (admin, venditori approvati e in attesa, box, clienti, ordini in vari stati).

## 8. Strategia di test

| Livello | Strumenti | Cosa verifica |
|---|---|---|
| Unitari backend | **Pest/PHPUnit** | formula del prezzo e del guadagno, finestre orarie, calcolo del payout |
| Integrazione API | Pest/PHPUnit (feature test) contro `mysql-test`, `mailpit`, `stripe-mock`, `s3mock`, `wiremock` | auth con 2FA, ordine, prenotazione stock, QR, reclamo, rimborso |
| Concorrenza | script che lancia N acquisti simultanei | lo stock non scende mai sotto zero |
| Webhook | eventi Stripe firmati dal test | idempotenza, pagamento tardivo con rimborso automatico |
| Tempo | `Clock` finto | fine fascia, `NOT_PICKED_UP`, payout dopo 24 ore, scadenza reclamo |
| Unitari JS | **Jest** (app e admin) | anteprima del prezzo (stessi casi condivisi del backend), validazioni lato client |
| End-to-end admin | **Playwright** | coda verifiche, reclami, configurazione |
| End-to-end mobile | **Maestro** (flussi in YAML) | registrazione, acquisto, mostra QR, scansione |

Casi limite da coprire: fascia di ritiro più corta di 2 ore, cambio di orario legale, doppia scansione QR, reclamo fuori termine, venditore sospeso con ordini pagati.

## 9. Ordine di realizzazione

| Fase | Contenuto | Storie |
|---|---|---|
| 0 | Repository, Docker Compose (PHP, nginx, MySQL, Mailpit, S3Mock, WireMock, stripe-mock), CI, analisi statica (PHPStan/Pint, ESLint), seeder, `Clock` | – |
| 1 | Autenticazione con verifica email e 2FA via email, ruoli | US-A01…A07, C01, V01, S01 |
| 2 | Onboarding venditore, documenti, verifica admin, Stripe Connect (test) | US-V02…V05, S02 |
| 3 | Offerte, ricerca per distanza, scheda negozio *(modelli ricorrenti in coda)* | US-V06, V08…V11, N01…N05, C02, C03 |
| 4 | Carrello, prenotazione stock, checkout Stripe, webhook, email | US-C04…C08 |
| 5 | QR di ritiro, ordini del giorno, job di fine fascia, payout ritardato | US-C09, C10, V12…V16, X01, X02 |
| 6 | Recensioni, reclami, rimborsi, sospensioni, moderazione | US-C12, C14, V17, V18, S03…S06 |
| 7 | Configurazione, categorie, cruscotto, audit log, GDPR, box ricorrenti, rifinitura | US-S07…S10, C15, C16, V07, V19, X03…X06 |

Il **percorso minimo da far funzionare per primo**: registrazione con 2FA → venditore approvato → box → ricerca → acquisto (Stripe test) → QR → payout → recensione. Le fasi 4 e 5 sono le più delicate.

## 10. Rischi e decisioni aperte

1. **Due linguaggi, due implementazioni della formula del prezzo:** PHP nel backend, JavaScript nell'app. Mitigazione: i casi di test condivisi in `contracts/` e il server che ricalcola sempre.
2. **Geolocalizzazione in MySQL:** si usano coordinate decimali e `ST_Distance_Sphere`, coperti da test con indirizzi noti. Con molti negozi servirà un indice spaziale.
3. **Dipendenza dalle email:** 2FA e notifiche passano dallo stesso canale; nel prototipo Mailpit lo rende affidabile, ma una versione reale richiede un provider con buona consegna.
4. **Revoca dei trasferimenti:** se un reclamo viene accolto dopo il payout, il saldo del venditore può non coprirlo; il ritardo di 24 ore riduce il rischio.
5. **Fedeltà dei simulatori:** `stripe-mock` e `wiremock` non replicano ogni comportamento reale. Prima di un uso reale vanno ripetuti i flussi con Stripe in modalità test vera.
6. **Coda su database:** adatta a un prototipo; con volumi reali andrebbe sostituita con una coda dedicata.
7. **Margine per box, fiscalità e requisiti fitosanitari:** fuori ambito per il prototipo; da rivalutare se l'app diventa commerciale.
