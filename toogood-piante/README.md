# TooGood Piante

App in stile "Too Good To Go" per box a sorpresa di piante. Prototipo per un progetto di studio.
Documenti di riferimento (nella cartella superiore): `UserStory-v4.md` e `PianoTecnico.md`.

Stato: **Fase 0** (infrastruttura). Il codice applicativo parte dalla Fase 1.

## Struttura

```
apps/api         Laravel (PHP 8.4): API, code, scheduler
apps/mobile      app React Native / Expo (non ancora creata)
apps/admin       pannello admin React/Vite (non ancora creato)
contracts/       specifica OpenAPI e casi di test condivisi (non ancora creata)
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

## Servizi in locale

| Servizio | Indirizzo | Cosa è |
|---|---|---|
| API | http://localhost:8080 | Laravel dietro nginx |
| Mailpit | http://localhost:8025 | Tutte le email inviate dall'app (2FA, conferme d'ordine) |
| MySQL | localhost:3307 | utente `toogood` / password `secret` / database `toogood` (solo sviluppo) |
| stripe-mock | http://localhost:12111 | API Stripe simulata (senza stato né webhook) |
| WireMock | http://localhost:8089 | Geocoding e verifica P.IVA simulati (vedi `docker/wiremock/mappings`) |
| S3Mock | http://localhost:9090 | Storage file compatibile S3, in memoria |
| Adminer *(profilo `tools`)* | http://localhost:8081 | Interfaccia web per MySQL |
| stripe-cli *(profilo `stripe`)* | – | Inoltra i webhook della modalità test Stripe (serve `STRIPE_API_KEY`) |

Profili opzionali: `docker compose --profile tools up -d`.

## Test

I test usano un database separato in memoria (`mysql-test`) e non toccano mai quello di sviluppo.

```bash
docker compose --profile test run --rm php-test php artisan test
```

Il servizio `php-test` usa `mysql-test` e ha le variabili d'ambiente di test già impostate. Non lanciare `php artisan test` dal servizio `php`: userebbe il database di sviluppo e la protezione in `tests/TestCase.php` lo blocca.

## Orologio simulato

Con `CLOCK_MODE=fake` (impostato in sviluppo) si può far avanzare il tempo per provare fine ritiro, payout dopo 24 ore e scadenze, senza aspettare:

```bash
docker compose exec php php artisan clock now
docker compose exec php php artisan clock advance 24h
docker compose exec php php artisan clock reset
```

## Dati simulati nei servizi esterni

- **Geocoding:** indirizzi che **finiscono** con "Milano" o "Roma" restituiscono coordinate; "TIMEOUT" simula un timeout; altri non vengono trovati.
- **Partita IVA:** `IT12345678901` è valida; `IT99999999999` simula servizio non disponibile; le altre non sono valide.
