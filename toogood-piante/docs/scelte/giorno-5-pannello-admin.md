# Giorno 5 – Pannello admin web: perché queste scelte

Obiettivo del giorno: l'admin approva o rifiuta i venditori da una pagina web, e il venditore vede l'esito in app. Riferimenti: US-S02 in `UserStory-v4.md`; endpoint in [API-venditore.md](../API-venditore.md). La schermata di stato del venditore in app esisteva già dal giorno 4: il lavoro nuovo è il sito.

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Un sito separato, non una sezione dell'app
- **Cosa:** `apps/admin`, React + Vite in JavaScript, come previsto dal piano tecnico.
- **Perché:** l'admin lavora al PC, confronta documenti e tabelle; l'app mobile è pensata per clienti e venditori. Tenerli separati evita di mettere strumenti di moderazione nel telefono di tutti.
- **Alternativa:** pagine Blade servite da Laravel. Più semplice da avviare, ma il piano e le storie successive (cruscotto, reclami) richiedono una vera applicazione client.

## 2. Stessa API dell'app, nessun endpoint nuovo
- **Cosa:** il pannello usa gli endpoint `/admin/sellers` del giorno 4 e lo stesso accesso con 2FA dell'app.
- **Perché:** una sola logica di autenticazione e di permessi, già coperta da test. Il ruolo è controllato dal server (`role:ADMIN`); il pannello in più rifiuta subito chi non è admin, per non lasciare token in giro.

## 3. CORS esplicito e ristretto
- **Cosa:** `apps/api/config/cors.php` ammette solo `http://localhost:5173` e `http://127.0.0.1:5173`, configurabile con `CORS_ALLOWED_ORIGINS`; solo le rotte `api/*`; senza cookie (`supports_credentials = false`).
- **Perché:** il browser blocca le richieste tra origini diverse. L'app mobile non è un browser e non ne ha bisogno. Una lista chiusa è più sicura di `*`. La porta di Vite è fissa (`strictPort`) per lo stesso motivo.

## 4. Token: access in memoria, refresh in `sessionStorage`
- **Cosa:** l'access token (15 minuti) resta in memoria; il refresh token in `sessionStorage`, quindi sopravvive al ricaricamento ma si perde chiudendo la scheda.
- **Perché:** un pannello admin non deve restare aperto per giorni. `localStorage` sarebbe più comodo ma meno prudente.
- **Limite noto:** qualunque script nella pagina può leggere `sessionStorage`; per un prototipo con dati finti è accettabile, per la produzione si userebbero cookie `HttpOnly`.

## 5. I documenti si scaricano con il token
- **Cosa:** un link diretto non può portare l'header `Authorization`, quindi il pannello scarica il file con `fetch`, lo trasforma in un indirizzo temporaneo (`blob:`) e lo apre in una nuova scheda.
- **Perché:** lo storage dei documenti è privato e passa solo dall'API (giorno 4): così restano visibili solo all'admin.

## 6. Rifiuto con motivo obbligatorio
- **Cosa:** il pulsante di conferma resta spento sotto i 5 caratteri (come la regola del server); il motivo arriva al venditore per email e si vede in app.
- **Perché:** un rifiuto senza spiegazione obbliga il venditore a indovinare cosa correggere (US-V04).

## 7. Cosa non c'è (ancora)
Aggiornamento automatico della lista, paginazione, sospensione dei venditori (giorno 13), reclami e rimborsi (giorno 12), test automatici del pannello (roadmap R5).
