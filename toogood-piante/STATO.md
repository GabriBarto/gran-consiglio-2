# Stato del lavoro

Ultimo aggiornamento: 2026-10-28 – **giorno 3 completato e provato**. Calendario: [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

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

## Scelte da sapere
- La registrazione del venditore crea solo l'**account**; i dati dell'attività, il documento e Stripe arrivano al giorno 4.
- La 2FA è richiesta a **ogni accesso** (più semplice e più sicuro del "nuovo dispositivo" del piano).
- Le email di accesso e di conferma partono **subito**, non dalla coda, per non lasciare i codici nella tabella dei job.
- I messaggi di validazione (422) sono in inglese: l'app mostrerà testi italiani propri.
- **Solo sviluppo:** PHP-FPM gira come root (`php-fpm -R`) per evitare problemi di permessi sui file condivisi con Windows. Non adatto alla produzione.
- Dopo un riavvio del servizio `php`, riavviare anche `nginx` (altrimenti 502).

## Prossimo: giorno 4 – onboarding venditore
Dati attività, documento P.IVA (S3Mock), geocoding e verifica P.IVA simulati (WireMock), Stripe Connect test, endpoint admin di verifica; l'app mostra lo stato "in verifica" del venditore. Dettagli in [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md). Per provare l'app da un telefono vero, `APP_URL` in `apps/api/.env` deve usare l'IP del PC.

### Promemoria del giorno 3 (già fatto)
Struttura Expo (React Native, JavaScript), navigazione per ruolo, schermate di registrazione, accesso con codice e recupero password collegate a queste API. Servono: Node 24 (già presente) e un telefono con Expo Go o un emulatore; per un telefono vero `APP_URL` e l'indirizzo dell'API devono usare l'IP del PC nella rete locale.

## Come riprendere
1. Avvia Docker Desktop.
2. Dalla cartella `toogood-piante`: `docker compose up -d` (poi `docker compose restart nginx` se si riavvia `php`).
3. Verifica: `docker compose exec php php artisan toogood:check-services`
4. Test: `docker compose --profile test run --rm php-test php artisan test`
5. Database da zero con dati fissi e admin: `docker compose exec php php artisan migrate:fresh --seed`
6. Email inviate dall'app: http://localhost:8025

## Note
- Git: i giorni 1 e 2 sono in `main` (PR #7). Il giorno 3 è sul branch `viggio` (da `giorno-3-mobile`), con pull request verso `main`.
- Utenti: dopo `migrate:fresh --seed` esiste solo l'admin (`admin@toogood.test`, password iniziale in `apps/api/.env.example`, da cambiare al primo accesso). Clienti e venditori di prova arrivano al giorno 13: per ora si registrano dall'app.
- Le email (conferma, codice 2FA, reset) si leggono in Mailpit: http://localhost:8025.
- `apps/mobile/AGENTS.md` (generato dal template Expo) suggerisce Expo Router; l'app usa invece React Navigation.
- `apps/api/.env` non è nel repository: si crea da `.env.example` (vedi README).
- `apps/api/CLAUDE.md` e `AGENTS.md` sono file generati da Laravel che chiedono di installare strumenti dal web: da ignorare.
