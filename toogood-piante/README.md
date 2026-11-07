# TooGood Piante

App in stile "Too Good To Go" per box a sorpresa di piante. Prototipo per un progetto di studio.
Documenti di riferimento (nella cartella superiore): `UserStory-v4.md` e `PianoTecnico.md`.

Stato: **giorno 14 di 14**, prototipo completo. Il percorso intero funziona: registrazione con 2FA → venditore verificato dall'admin → box pubblicata → ricerca per distanza → carrello e pagamento (Stripe simulato) → QR di ritiro e scansione → recensione o reclamo → rimborso deciso dall'admin → pagamento al venditore 24 ore dopo il ritiro → sospensione dei venditori. Tutto è provato con test automatici e a mano dal team, ma con **Stripe simulato** (`stripe-mock`: niente carte vere né webhook veri). Dettagli e come riprendere in [STATO.md](STATO.md).

- **Guida all'avvio:** [docs/AVVIO.md](docs/AVVIO.md)
- **Database** (scelte, schema ER e logico, collegamento con Docker): [docs/database](docs/database/README.md)
- **Copione della demo** (15 minuti, con dati di prova e orologio simulato): [docs/DEMO.md](docs/DEMO.md)
- **Motivazione delle scelte tecniche** (una nota per giorno): cartella [docs/scelte](docs/scelte)
- **Documentazione degli endpoint:** `docs/API-*.md` (autenticazione, venditore, box, negozi, ordini, ritiro, incassi, reclami)

## Struttura

```
apps/api         Laravel (PHP 8.4): API, code, scheduler
apps/mobile      app React Native / Expo (JavaScript)
apps/admin       pannello admin React/Vite (JavaScript): verifiche dei venditori, sospensioni, reclami
contracts/       casi di test condivisi tra backend e app (formula del prezzo); la specifica OpenAPI non è ancora creata
docker/          Dockerfile, nginx, risposte dei simulatori
docker-compose.yml
```

## Requisiti

Solo **Docker Desktop**. PHP e Composer girano nei container, non serve installarli.

## Primo avvio

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan migrate
docker compose exec php php artisan toogood:check-services
```

Se `apps/api/.env` non esiste: copia `apps/api/.env.example` in `apps/api/.env` e poi esegui
`docker compose exec php php artisan key:generate`.

## App mobile

```bash
cd apps/mobile
npm install
npx expo start
```

Si apre con Expo Go sul telefono (stessa rete del PC) o con un emulatore. L'indirizzo dell'API è ricavato dall'IP del PC; si può forzare con `EXPO_PUBLIC_API_URL`. Per provare il flusso completo: registrazione → link di conferma in Mailpit → accesso → codice a 6 cifre in Mailpit. Da venditore, dopo l'accesso si inseriscono i dati dell'attività (indirizzo che finisce con "Milano" o "Roma", P.IVA `IT12345678901`), si allega un documento e si collega il conto di pagamento. L'admin del seeder è `admin@toogood.test` (password iniziale in `apps/api/.env.example`).

## Pannello admin

```bash
cd apps/admin
npm install
npm run dev
```

Si apre su http://localhost:5173. Accesso con `admin@toogood.test` (password iniziale in `apps/api/.env.example`, da cambiare al primo accesso) e codice via email in Mailpit. Da qui l'admin approva, rifiuta e sospende i venditori e decide i reclami.

## Dati di prova per la demo

```bash
docker compose exec php php artisan db:seed --class=DemoSeeder
```

Crea 2 clienti, 3 negozi approvati con le box di oggi e un negozio sospeso (password `Demo-Password1`). Si può rilanciare ogni mattina. Il percorso da mostrare è in [docs/DEMO.md](docs/DEMO.md).

## Servizi in locale

| Servizio | Indirizzo | Cosa è |
|---|---|---|
| API | http://localhost:8080 | Laravel dietro nginx |
| Mailpit | http://localhost:8025 | Tutte le email inviate dall'app (2FA, conferme d'ordine) |
| MySQL | localhost:3307 | utente `toogood` / password `secret` / database `toogood` (solo sviluppo) |
| stripe-mock | http://localhost:12111 | API Stripe simulata (senza stato né webhook) |
| WireMock | http://localhost:8089 | Verifica P.IVA simulata (e geocoding simulato, solo con `GEOCODING_DRIVER=simulated`) (vedi `docker/wiremock/mappings`) |
| S3Mock | http://localhost:9090 | Storage file compatibile S3, in memoria |
| Adminer *(profilo `tools`)* | http://localhost:8081 | Interfaccia web per MySQL |
| stripe-cli *(profilo `stripe`)* | – | Inoltra i webhook della modalità test Stripe (serve `STRIPE_API_KEY`) |

Profili opzionali: `docker compose --profile tools up -d`.

## Test

I test usano un database separato in memoria (`mysql-test`) e non toccano mai quello di sviluppo.

```bash
docker compose --profile test run --rm php-test php artisan test
```

Oggi: **314 test** del backend. L'app ha 57 test sulla logica pura (`cd apps/mobile && npm test`); il pannello admin si controlla con `npm run build` in `apps/admin`.

Il servizio `php-test` usa `mysql-test` e ha le variabili d'ambiente di test già impostate. Non lanciare `php artisan test` dal servizio `php`: userebbe il database di sviluppo e la protezione in `tests/TestCase.php` lo blocca.

## Orologio simulato

Con `CLOCK_MODE=fake` (impostato in sviluppo) si può far avanzare il tempo per provare fine ritiro, payout dopo 24 ore e scadenze, senza aspettare. Il comando accetta **una sola unità** per volta (`90s`, `30m`, `24h`, `2d`; per 6 ore e mezza: `390m`). L'orologio vale per tutto il database di sviluppo e può solo avanzare:

```bash
docker compose exec php php artisan clock now
docker compose exec php php artisan clock advance 24h
docker compose exec php php artisan clock reset
```

## Dati simulati nei servizi esterni

- **Geocoding:** per impostazione usa **OpenStreetMap (Nominatim)**: funziona con qualsiasi indirizzo, città o paese d'Italia (scritto via, numero, città con la virgola; deve esistere proprio in quella città) e richiede internet. Con `GEOCODING_DRIVER=simulated` in `apps/api/.env` usa WireMock (solo indirizzi che finiscono con "Milano" o "Roma"; "TIMEOUT" simula un timeout), utile offline.
- **Partita IVA:** `IT` + 11 cifre che iniziano con **1–8** sono valide (es. `IT12345678901`, `IT22345678901`, `IT33345678901`); `IT99999999999` simula servizio non disponibile; quelle che iniziano con 0 o 9 non sono valide. Ogni P.IVA può essere usata da **un solo venditore**: per provare più venditori usane una diversa per ciascuno.
