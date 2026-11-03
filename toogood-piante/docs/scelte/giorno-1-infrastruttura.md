# Giorno 1 – Infrastruttura e schema dati: perché queste scelte

Obiettivo del giorno: avere un ambiente che qualsiasi membro del gruppo (o un esaminatore) possa avviare con un comando, senza account esterni, e un database già pronto per tutte le funzioni del prodotto (box, ordini, pagamenti, recensioni, reclami).

Ogni scelta è descritta così: **cosa**, **perché**, **alternativa scartata**, **dove si vede nel codice**.

---

## 1. Tutto in Docker Compose

- **Cosa:** un solo `docker-compose.yml` avvia PHP-FPM, nginx, coda, scheduler, MySQL, Mailpit, S3Mock, stripe-mock e WireMock.
- **Perché:** l'unico requisito per chi clona il progetto è Docker Desktop. PHP, Composer e MySQL non vanno installati e le versioni sono uguali per tutti (PHP 8.4, MySQL 8.4). In presentazione "funziona sul mio PC" smette di essere un rischio.
- **Alternativa scartata:** installare PHP/MySQL a mano su ogni macchina (versioni diverse, configurazioni diverse, tempo perso).
- **Dove:** [docker-compose.yml](../../docker-compose.yml), [docker/php/Dockerfile](../../docker/php/Dockerfile), [docker/nginx/default.conf](../../docker/nginx/default.conf).

## 2. Servizi esterni simulati (nessuna chiave, nessun costo)

Il prodotto dipende da servizi esterni (email, pagamenti, storage, geocoding, verifica P.IVA). In sviluppo ognuno è sostituito da un simulatore locale.

| Servizio reale | Simulatore | Perché |
|---|---|---|
| Invio email | **Mailpit** | Le email (codici 2FA, link di conferma) si leggono in una pagina web, senza un vero provider SMTP e senza mandare posta a indirizzi reali. |
| Stripe | **stripe-mock** | Le API di pagamento rispondono come Stripe, senza chiavi e senza addebiti. Limite noto: non ha stato né webhook (il profilo `stripe` con stripe-cli è previsto per provarli). |
| Storage file (S3) | **S3Mock** (Adobe) | I documenti dei venditori finiscono in un bucket locale. MinIO era la prima scelta ma non è più scaricabile dai registri pubblici (commento nel compose). |
| Geocoding e verifica P.IVA (VIES) | **WireMock** | Risposte fisse e prevedibili, comprese quelle di errore (timeout, servizio non disponibile), così si possono dimostrare e testare anche i casi sfortunati. |

- **Perché in generale:** la demo è ripetibile (stessi dati, stesso risultato), funziona senza internet e non espone chiavi segrete nel repository.
- **Dove:** [docker/wiremock/mappings](../../docker/wiremock/mappings); gli indirizzi dei servizi sono variabili in `apps/api/.env.example` e in `config/toogood.php`, quindi in produzione basta cambiarle senza toccare il codice.
- **Comando di controllo:** `php artisan toogood:check-services` ([CheckServices.php](../../apps/api/app/Console/Commands/CheckServices.php)) verifica che tutti i servizi rispondano.

## 3. Laravel 13 / PHP 8.4 per l'API

- **Cosa:** backend in Laravel, con code e scheduler come servizi a parte (`queue`, `scheduler` nel compose).
- **Perché:** il prodotto ha lavori ritardati e periodici (scadenza delle prenotazioni di stock, payout 24 ore dopo il ritiro, chiusura delle fasce di ritiro). Laravel li gestisce nativamente con code e scheduler; lo stesso vale per validazione, limiti di richieste, email e test. È scritto in un linguaggio che il gruppo può leggere e modificare in fretta.
- **Dove:** `apps/api`.

## 4. MySQL con due database distinti: sviluppo e test

- **Cosa:** `mysql` (dati persistenti, porta 3307) e `mysql-test` (in memoria con `tmpfs`, porta 3308, profilo `test`).
- **Perché:** i test cancellano e ricreano le tabelle. Con un database separato non si possono perdere per errore i dati di sviluppo. In più `tests/TestCase.php` **si rifiuta di partire** se il database non finisce con `_test`: la protezione non dipende dalla memoria di chi lancia i comandi.
- **Perché MySQL:** le distanze tra cliente e negozio si calcolano con `ST_Distance_Sphere` direttamente in SQL (commento in `create_sellers_tables`), e il vincolo `CHECK` sullo stock è supportato.
- **Dove:** [tests/TestCase.php](../../apps/api/tests/TestCase.php), servizio `php-test` nel compose.

## 5. Orologio simulato (`Clock`)

- **Cosa:** una classe `Clock` è l'unica fonte dell'ora per la logica di business. Con `CLOCK_MODE=fake` si può avanzare il tempo con `php artisan clock advance 24h`.
- **Perché:** molte regole dipendono dal tempo: fine della fascia di ritiro, ultimo orario per ordinare (2 ore prima), payout dopo 24 ore. In una demo non si può aspettare un giorno. Con l'orologio simulato si mostrano in pochi secondi. In produzione (`CLOCK_MODE=real`) non si può spostare: `advance` lancia un errore.
- **Dove:** [Clock.php](../../apps/api/app/Support/Clock.php), [ClockCommand.php](../../apps/api/app/Console/Commands/ClockCommand.php), test in `ClockTest.php`.

## 6. Schema del database progettato subito per tutto il prodotto

Le migrazioni coprono già anche le funzioni dei giorni successivi. Motivo: cambiare lo schema a metà progetto è la cosa che fa più danni; farlo bene una volta sola evita rifacimenti.

Scelte puntuali, tutte spiegate da commenti nelle migrazioni:

- **Importi in centesimi interi** (`price_cents`, `fee_cents`, …). I numeri decimali sui soldi producono errori di arrotondamento; con gli interi i conti tornano sempre.
- **Snapshot nel momento dell'acquisto.** La tariffa di servizio è copiata nell'offerta (`fee_cents`), il prezzo unitario negli articoli dell'ordine, la fine della fascia di ritiro nell'ordine (`pickup_window_end_at`). Se domani la tariffa cambia, gli ordini già fatti restano corretti e verificabili.
- **Parametri di business in tabella** (`config_params`: tariffa 150 cent, sconto 60–70%, limite ordine 2 ore, payout 24 ore). Si cambiano senza modificare il codice. Il seeder non sovrascrive un valore già modificato (verificato da un test).
- **Dati fissi in tabelle gestite da seeder** (`box_types`, `categories`, `plant_attributes`) invece di costanti nel codice: in futuro un pannello admin potrà modificarli (roadmap R6).
- **Vincolo sullo stock nel database** (`CHECK stock_available <= stock_total`). Se un bug nel codice provasse a vendere più box di quelle esistenti, il database rifiuta l'operazione: l'ultima barriera contro la vendita in eccesso.
- **Prenotazione dello stock (`stock_reservations`) con scadenza.** Mentre il cliente paga, le box sono "tenute da parte"; se non paga, la scadenza le rilascia.
- **`pickup_tokens` con `jti` e codice breve univoci, `used_at` e `scanned_by`.** Un QR di ritiro funziona una volta sola e si sa chi lo ha letto.
- **`stripe_events` con l'id evento come chiave primaria.** Stripe può inviare lo stesso webhook più volte; l'id univoco impedisce di elaborarlo due volte (niente doppi rimborsi o payout).
- **`restrictOnDelete` sulle relazioni con soldi e storico** (ordini, payout, recensioni, reclami). Non si può cancellare un venditore o un'offerta che ha già ordini: lo storico contabile resta intatto. Solo le tabelle di dettaglio (articoli, documenti) usano `cascadeOnDelete`.
- **`softDeletes` sulle offerte**: un'offerta ritirata sparisce dall'elenco ma resta consultabile dagli ordini che la citano.
- **Date sempre in UTC nel database, fuso `Europe/Rome` solo per calcolare "oggi" e le fasce** (`config/toogood.php`). Evita errori nei cambi ora legale/solare.

Test di schema: [SchemaTest.php](../../apps/api/tests/Feature/SchemaTest.php).

## 7. Compromesso dichiarato: PHP-FPM come root (solo sviluppo)

- **Cosa:** `php-fpm -R` e `user = root` nel Dockerfile.
- **Perché:** sui dischi condivisi di Windows i permessi dei file non sono affidabili; con utenti diversi i file di log e cache creati da un processo non erano scrivibili dall'altro. Per non far perdere tempo al gruppo si è scelto di far girare tutto come root **solo in locale**.
- **Da dire in presentazione:** è una scelta consapevole e documentata nel Dockerfile, non adatta alla produzione, dove si userebbe un utente non privilegiato.
