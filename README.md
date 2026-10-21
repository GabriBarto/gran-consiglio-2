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

Durante la presentazione il pulsante **“↺ Reset dati demo”** nel footer ripristina i dati senza riavviare
(disattivabile con `DEMO_MODE=false`).

## Percorso suggerito per la demo

1. **Cliente** – Home: statistiche, filtri per città/categoria/ricerca → *Prenota* una sorpresa → compare il **codice di ritiro**.
2. **Venditore** – “Per i venditori”, accedi con un negozio demo → pubblica una nuova offerta, poi inserisci il codice del cliente in **Conferma ritiro**.
3. Torna alla home: le statistiche (piante salvate, risparmio) sono aggiornate.

PIN demo dei negozi: Vivaio Il Glicine `1111` · Fiori & Fiori di Marta `2222` · Garden Verde `3333` ·
Succulente Matte `4444` · Il Bosso e la Rosa `5555`.

## App mobile (React Native / Expo)

La cartella `mobile/` contiene un'unica app per clienti e venditori (QR di ritiro + scanner per i negozianti),
che usa lo stesso backend. Istruzioni in [`mobile/README.md`](mobile/README.md).

## Architettura

- **Backend**: Node.js 22, zero dipendenze (server HTTP nativo + `node:sqlite`). Nessun `npm install`, build veloce.
- **Database**: SQLite su volume Docker (`/data`), con seed demo automatico al primo avvio. Le date delle offerte sono relative a "adesso", quindi la demo è sempre popolata.
- **Frontend**: SPA in JavaScript vanilla (`app/public`), nessuna CDN: funziona anche offline.
- **Docker**: immagine `node:22-alpine`, utente non-root, healthcheck su `/health`.

```
app/
  server.js   API REST + file statici
  db.js       schema SQLite
  seed.js     dati demo
  public/     interfaccia web (index.html, app.js, style.css)
Dockerfile · docker-compose.yml · Makefile
```

API principali: `GET /api/offers`, `POST /api/reservations`, `GET /api/reservations/:codice`,
`POST /api/seller/login`, `GET /api/seller/dashboard`, `POST /api/seller/offers`, `POST /api/seller/redeem`.

> Nota: è un prototipo da presentazione. PIN in chiaro, sessioni in memoria e pagamento "in negozio" vanno
> sostituiti (autenticazione vera, pagamenti online, notifiche email) per un uso reale.
