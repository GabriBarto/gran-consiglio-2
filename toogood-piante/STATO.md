# Stato del lavoro

Ultimo aggiornamento: 2026-10-28 – **giorno 1 completato**. Calendario: [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Fatto (giorno 1)
- Docker Compose funzionante: PHP 8.4, nginx, queue, scheduler, MySQL 8.4, Mailpit, S3Mock, stripe-mock, WireMock (+ `mysql-test`, `php-test`, adminer, stripe-cli come profili).
- Laravel 13 in `apps/api`, con `stripe-php` 22 e client S3.
- `Clock` simulato + comando `php artisan clock`.
- `php artisan toogood:check-services`: 6 controlli su 6 verdi.
- **Schema del database** (migrazioni in `apps/api/database/migrations`): utenti con ruoli, codici 2FA, refresh token, venditori e documenti, tipologie/categorie/attributi, parametri di business, offerte (box) con soft delete, ordini e righe, prenotazioni stock, QR di ritiro, payout, recensioni, reclami, rimborsi, eventi Stripe.
- **Dati fissi** (`CatalogSeeder`, rieseguibile): 3 tipologie, 7 categorie, 10 attributi, parametri (tariffa 150 centesimi, sconto 60–70%, limite ordine 2 ore, payout 24 ore, reclamo 24 ore, prenotazione 10 minuti).
- **Test: 13 su 13 passano** (orologio, schema, stock atomico, ricerca per distanza, protezione dall'assegnazione del ruolo).

## Scelte di schema da conoscere
- Importi sempre in **centesimi interi**; sconto in percentuale intera.
- Coordinate dei negozi in **colonne decimali** (`latitude`, `longitude`) e distanza con `ST_Distance_Sphere`: scelto al posto del tipo spaziale per evitare le particolarità dell'ordine delle coordinate. `PianoTecnico.md` è aggiornato.
- MVP: **un'unica fascia di ritiro** per venditore (colonne `pickup_from`/`pickup_to` su `sellers`); orari per giorno e box ricorrenti sono in roadmap.
- `role` e `status` degli utenti non sono assegnabili in massa.

## Prossimo: giorno 2 – autenticazione
Registrazione cliente/venditore, verifica email, login con codice 2FA via email, refresh token, logout, reset password, admin creato dal seeder con cambio password al primo accesso, rate limiting, test con le email lette da Mailpit.

## Come riprendere
1. Avvia Docker Desktop.
2. Dalla cartella `toogood-piante`: `docker compose up -d`
3. Verifica: `docker compose exec php php artisan toogood:check-services`
4. Test: `docker compose --profile test run --rm php-test php artisan test`
5. Database da zero con dati fissi: `docker compose exec php php artisan migrate:fresh --seed`

## Note
- Il lavoro è sul branch Git `fase-0-setup` della cartella `progetto`; **nessun commit fatto**.
- La cartella `gran-consiglio` (repository del team) non è usata né modificata.
- `apps/api/CLAUDE.md` e `AGENTS.md` sono file generati da Laravel che chiedono di installare strumenti dal web: da ignorare.
