# Stato del lavoro

Ultimo aggiornamento: 2026-11-03 – **giorno 4 completato e provato sull'API; schermate mobili non ancora provate su telefono**. Giorni 1–3 in `main`; il giorno 4 è sul branch `giorno-4-venditore` (non committato, non pubblicato). Calendario: [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md).

## Documenti
- **Come avviare l'app** (comandi, dove leggere le email di conferma): [docs/AVVIO.md](docs/AVVIO.md)
- **Perché abbiamo scelto così** (per la presentazione):
  - [Giorno 1 – infrastruttura e schema dati](docs/scelte/giorno-1-infrastruttura.md)
  - [Giorno 2 – autenticazione](docs/scelte/giorno-2-autenticazione.md)
  - [Giorno 3 – app mobile](docs/scelte/giorno-3-app-mobile.md)
  - [Giorno 4 – onboarding venditore](docs/scelte/giorno-4-onboarding-venditore.md)
- Endpoint di autenticazione: [docs/API-auth.md](docs/API-auth.md)
- Endpoint del venditore e della verifica admin: [docs/API-venditore.md](docs/API-venditore.md)

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
- Il pannello admin web e la schermata d'approvazione arrivano al giorno 5: per ora si approva con le chiamate API (vedi sotto).

## Scelte da sapere
- Il profilo dell'attività si invia **dopo** la registrazione e dopo l'accesso, non nel modulo di registrazione.
- Una P.IVA inesistente è rifiutata; se il servizio di verifica è giù il profilo si salva con controllo "non verificato" e decide l'admin.
- Con `stripe-mock` il conto risulta sempre collegato appena si preme "controlla": il link di Stripe è solo un esempio. Per la procedura vera servono chiavi di test e `STRIPE_API_BASE` vuoto.
- I dati di un'attività approvata non si modificano dal flusso di onboarding.
- La 2FA è richiesta a **ogni accesso** (più semplice e più sicuro del "nuovo dispositivo" del piano).
- Le email di accesso e di conferma partono **subito**, non dalla coda, per non lasciare i codici nella tabella dei job.
- I messaggi di validazione (422) dell'API non sono tradotti (compaiono chiavi come `validation.unique`): l'app mostra testi italiani propri scelti in base al campo.
- **Solo sviluppo:** PHP-FPM gira come root (`php-fpm -R`) per evitare problemi di permessi sui file condivisi con Windows. Non adatto alla produzione.
- Se l'API dà 502 dopo un riavvio, `docker compose restart nginx` (la configurazione attuale lo evita: nginx risolve `php` a ogni richiesta).

## Prossimo: giorno 5 – pannello admin web e stato in app
Pannello admin web (React/Vite) con la coda delle verifiche; approvo un venditore dal web e lui lo vede in app. L'API per farlo esiste già (`/api/admin/sellers`). Dettagli in [../MVP-e-Roadmap.md](../MVP-e-Roadmap.md). Per provare l'app da un telefono vero, `APP_URL` in `apps/api/.env` deve usare l'IP del PC.

### Come approvare un venditore, in attesa del pannello (giorno 5)
Serve il token dell'admin (accesso con codice da Mailpit). Con quel token:
- `GET /api/admin/sellers` elenca le richieste;
- `POST /api/admin/sellers/{id}/approve` approva;
- `POST /api/admin/sellers/{id}/reject` con `{"note": "motivo"}` rifiuta.

Il venditore vede il nuovo stato nell'app toccando "Aggiorna stato" e riceve l'email in Mailpit.

## Come riprendere
Guida completa: [docs/AVVIO.md](docs/AVVIO.md). In breve:
1. Avvia Docker Desktop.
2. Dalla cartella `toogood-piante`: `docker compose up -d`.
3. Verifica: `docker compose exec php php artisan toogood:check-services`
4. Test: `docker compose --profile test run --rm php-test php artisan test`
5. Database da zero con dati fissi e admin: `docker compose exec php php artisan migrate:fresh --seed`
6. Email inviate dall'app: http://localhost:8025

## Note
- Git: i giorni 1 e 2 sono in `main` (PR #7), il giorno 3 pure (PR #8, dal branch `viggio`). Il giorno 4 è sul branch locale `giorno-4-venditore`, creato da `rebs` (che contiene anche i documenti `docs/AVVIO.md` e `docs/scelte`, pubblicati ma senza PR): **nulla del giorno 4 è stato committato né pubblicato**.
- Su questo PC non c'è Node: per i comandi `npm`/`npx` si usa un container (`docker run --rm -v <cartella>:/app -w /app node:24-alpine ...`).
- Utenti: dopo `migrate:fresh --seed` esiste solo l'admin (`admin@toogood.test`, password iniziale in `apps/api/.env.example`, da cambiare al primo accesso). Clienti e venditori di prova arrivano al giorno 13: per ora si registrano dall'app.
- Le email (conferma, codice 2FA, reset) si leggono in Mailpit: http://localhost:8025.
- `apps/mobile/AGENTS.md` (generato dal template Expo) suggerisce Expo Router; l'app usa invece React Navigation.
- `apps/api/.env` non è nel repository: si crea da `.env.example` (vedi README).
- `apps/api/CLAUDE.md` e `AGENTS.md` sono file generati da Laravel che chiedono di installare strumenti dal web: da ignorare.
