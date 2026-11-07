# API del ritiro con QR (giorno 10)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.

## Il percorso

```
ordine PAID ──▶ nasce il biglietto (QR firmato + codice alternativo, valido solo nella fascia di ritiro del giorno)
     │
     ├─ il cliente mostra il QR ──▶ il venditore lo scansiona ──▶ PICKED_UP   (data e ora registrate)
     │
     └─ fine fascia, nessuna scansione ──▶ NOT_PICKED_UP                      (nessun rimborso, RB-09)
```

Il biglietto si crea **nel momento in cui l'ordine risulta pagato** (stessa transazione) e compare anche nell'email di conferma come codice alternativo. Per gli ordini pagati prima di questa funzione si crea alla prima richiesta del cliente.

## Endpoint

| Metodo | Percorso | Chi | Descrizione |
|---|---|---|---|
| GET | `/orders/{id}/pickup` | cliente | Il biglietto di un **proprio** ordine pagato o già ritirato. 409 `INVALID_STATE` per gli altri stati, 404 se è di un altro. |
| POST | `/seller/pickup/scan` | venditore | Scansiona un QR o un codice e conferma il ritiro. Limite: 30 richieste al minuto per utente. |
| GET | `/seller/orders?date=YYYY-MM-DD` | venditore | Gli ordini del negozio nel giorno (oggi se omesso) con lo stato del ritiro. |

### Il biglietto (`GET /orders/{id}/pickup`)

```
{ order_id, number, qr, code, state, valid_from, valid_until, used_at, shop: { business_name, address } }
```

- `qr`: il testo da mettere nel QR, `TGQR1.<dati>.<firma>`. Contiene **solo** il numero dell'ordine e un identificativo casuale (nessun nome, email o importo), firmati con HMAC-SHA256 con una chiave derivata da `APP_KEY` e usata solo per i QR.
- `code`: il codice alternativo, `ABCD-EFGH` (8 caratteri senza `0 O 1 I L`, così si può dettare a voce o digitare).
- `valid_from`, `valid_until`: la fascia di ritiro del giorno dell'ordine, con il fuso di Roma (es. `2026-06-15T17:00:00+02:00`).
- `state`: `NOT_YET` (il ritiro non è iniziato), `ACTIVE`, `EXPIRED` (fascia finita) o `USED` (già ritirato). L'app lo ricalcola da sola con `valid_from`/`valid_until`/`used_at` per mostrarlo anche **senza rete**: il biglietto, una volta caricato, resta salvato sul telefono (US-C09 CA3).

### La scansione (`POST /seller/pickup/scan`)

Corpo: `{ "qr": "..." }` **oppure** `{ "code": "..." }` (uno dei due; minuscole, spazi e trattini nel codice non contano).
Esito 200: `{ "result": "OK", "order": { id, number, status: "PICKED_UP", customer: { username }, items, units, total_cents, picked_up_at } }`.

Controlli, in quest'ordine, con l'errore corrispondente:

| Controllo | HTTP | `code` |
|---|---|---|
| QR con firma o formato non validi, o identificativo sconosciuto | 422 | `INVALID_QR` |
| Codice digitato non esistente | 422 | `INVALID_CODE` |
| L'ordine è di **un altro negozio** (o il venditore non ha un'attività) | 403 | `WRONG_SHOP` |
| Biglietto **già usato** (risposta con `used_at`) | 409 | `ALREADY_USED` |
| Ordine non più ritirabile (rimborsato, in reclamo…) | 409 | `ORDER_NOT_ACTIVE` |
| Ordine già chiuso come non ritirato | 409 | `EXPIRED` |
| Prima dell'inizio della fascia (risposta con `valid_from`) | 409 | `NOT_YET_VALID` |
| Dopo la fine della fascia | 409 | `EXPIRED` |

Gli estremi della fascia sono **inclusi**: vale dal primo all'ultimo istante. Il giorno dopo non vale più.

**Una sola scansione riesce.** Ordine e biglietto si bloccano per la durata della transazione e l'uso si registra con un aggiornamento che modifica una riga solo se `used_at` è ancora vuoto: con più richieste insieme (QR e codice, o due telefoni) una passa e le altre ricevono `ALREADY_USED`. Provato dal vivo con 16 richieste simultanee: 1 riuscita, 15 rifiutate, nessun errore.

Un venditore **sospeso** può comunque scansionare: gli ordini già pagati si ritirano (US-S03 CA2). Il cliente non può usare questa rotta (403 `FORBIDDEN_ROLE`).

### Ordini del giorno (`GET /seller/orders`)

```
{ date, summary: { to_pick_up, units_to_pick_up, picked_up, not_picked_up }, data: [ { id, number, status, customer: { username }, items: [ { quantity, description } ], units, total_cents, pickup_window_end_at, picked_up_at } ] }
```

Compaiono gli ordini pagati e quelli già decisi (`PAID` da ritirare, `PICKED_UP`, `NOT_PICKED_UP`, in reclamo, rimborsati); non quelli mai pagati. Del cliente si vede **solo il nome utente**: né email né il suo codice di ritiro.

## Chiusura di fine fascia

Il comando **`orders:close-pickup`**, pianificato ogni minuto, porta a `NOT_PICKED_UP` gli ordini `PAID` la cui fascia è **finita** (strettamente dopo `pickup_window_end_at`: all'ultimo istante la scansione vale ancora, quindi l'ordine non si chiude). Ricontrolla lo stato con la riga bloccata: se il venditore ha scansionato un attimo prima, l'ordine non si tocca. Rieseguirlo non cambia nulla. Il payout al venditore (24 ore dopo) è il giorno 11.

Per provarlo in sviluppo: `docker compose exec php php artisan clock advance 6h`, poi `orders:close-pickup`, poi `clock reset`. **Attenzione:** l'orologio simulato è uno solo per tutto il database di sviluppo, quindi il comando chiude anche i *tuoi* ordini di prova la cui fascia, nel tempo simulato, risulta finita (restano `NOT_PICKED_UP` anche dopo `clock reset`).

## Limiti noti

- Il QR è valido per il **giorno dell'ordine**: un ordine fatto la sera per il ritiro del giorno dopo non esiste (le box si vendono solo per il giorno stesso, RB-06).
- La fascia è quella del negozio al momento del pagamento; una modifica successiva degli orari non cambia i biglietti già emessi.
- Nessun annullamento del ritiro: una scansione per errore non si può annullare dall'app (si correggerebbe da admin, ancora non previsto).
- La scansione con la fotocamera e la schermata del QR sono nell'app (Expo Go le supporta); provate dal team su telefono.
