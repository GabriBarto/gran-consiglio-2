# Gran Consiglio
## Gruppo di lavoro:

Rebecca Appetito
Gabriele Bartolini
Andrés Tirado Morante
Michele Vagnetti
Marco Viggiani


---

# 🌱 VerdeSalva

Un "Too Good To Go" per **vivai, fioristi e garden center**: i negozianti pubblicano a prezzo ridotto le
piante e i fiori invenduti ("sorprese verdi"), i clienti li prenotano e li ritirano con un codice.

## Avvio rapido (demo)

Requisiti: Docker + Docker Compose.

```bash
docker compose up -d --build     # oppure: make up
```

Apri **http://localhost:8080**. Per cambiare porta: `APP_PORT=9000 docker compose up -d --build` (vedi `.env.example`).

| Comando | Effetto |
|---|---|
| `make up` / `make down` | avvia / ferma (i dati restano nel volume) |
| `make reset` | cancella il volume e riparte con dati demo freschi |
| `make logs` | log dell'applicazione |
| `make test` | esegue i test automatici (backend + PostgreSQL) |

Durante la presentazione il pulsante **“↺ Reset dati demo”** nel footer ripristina i dati senza riavviare
(disattivabile con `DEMO_MODE=false`).

## Percorso suggerito per la demo

1. **Cliente** – Home: statistiche, filtri per città/categoria/ricerca → *Prenota* una sorpresa → compare il **codice di ritiro**.
2. **Venditore** – “Accedi · Venditori”, entra con un account demo → pubblica una nuova offerta, poi inserisci il codice del cliente in **Conferma ritiro**.
3. Torna alla home: le statistiche (piante salvate, risparmio) sono aggiornate.

### Account demo (password `demo1234`, tranne l'admin)

| Ruolo | Email |
|---|---|
| Venditore – Vivaio Il Glicine | `glicine@demo.it` |
| Venditore – Fiori & Fiori di Marta | `fiori@demo.it` |
| Venditore – Garden Verde Pistoia | `garden@demo.it` |
| Venditore – Succulente Matte | `succulente@demo.it` |
| Venditore – Il Bosso e la Rosa | `bosso@demo.it` |
| Cliente | `cliente@demo.it` |
| Super admin (password `admin1234`, da cambiare al primo accesso) | `admin@demo.it` |

Chiunque può anche **registrarsi** come cliente o venditore (email, nome utente, password con conferma).

## App mobile (React Native / Expo)

La cartella `mobile/` contiene un'unica app per clienti e venditori (QR di ritiro + scanner per i negozianti),
che usa lo stesso backend. Istruzioni in [`mobile/README.md`](mobile/README.md).

## Architettura

- **Backend**: Node.js 22 con server HTTP nativo e una sola dipendenza (`pg`, driver PostgreSQL).
- **Database**: **PostgreSQL 16** in un container dedicato, con volume persistente. Lo schema è versionato in `app/migrations/` e applicato all'avvio. Seed demo automatico a database vuoto; le date delle offerte sono relative a "adesso", quindi la demo è sempre popolata.
- **Autenticazione**: registrazione e login con email e password (hash `scrypt`), sessioni a token revocabili (salvate come hash), ruoli `cliente` / `venditore` / `admin`, limite ai tentativi di accesso, sospensione degli account.
- **Test**: `cd app && npm test` (serve un PostgreSQL via `DATABASE_URL`) oppure `make test` con Docker.
- **Frontend**: SPA in JavaScript vanilla (`app/public`), nessuna CDN: funziona anche offline.
- **Docker**: immagine `node:22-alpine`, utente non-root, healthcheck su `/health`.

```
app/
  server.js   avvio (attende il DB, migrazioni, seed)
  app.js      server HTTP e routing
  auth.js     password, sessioni, ruoli
  db.js       connessione PostgreSQL e migrazioni
  migrations/ schema SQL versionato
  routes/     endpoint (auth, pubblici, venditore, demo)
  seed.js     dati demo
  test/       test di integrazione
  public/     interfaccia web (index.html, app.js, style.css)
Dockerfile · docker-compose.yml · Makefile
```

API principali: `POST /api/auth/register|login|logout|change-password`, `GET /api/auth/me`,
`GET /api/offers`, `POST /api/reservations`, `GET /api/reservations/:codice`,
`GET /api/seller/dashboard`, `POST /api/seller/offers`, `POST /api/seller/redeem`.

La variabile `SERVICE_FEE_CENTS` (default 100) è la tariffa fissa trattenuta per ogni box venduta; l'importo reale è ancora da stabilire.

> Nota: è un prototipo. Restano da realizzare, come da [`ROADMAP.md`](ROADMAP.md), carrello e pagamento online,
> email di conferma, registrazione completa del negozio con partita IVA, pannello admin e recensioni.
