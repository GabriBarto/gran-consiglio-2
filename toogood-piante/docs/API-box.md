# API delle box (giorno 6)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.
Tutte le rotte sono solo per il ruolo `SELLER` (altrimenti 403 `FORBIDDEN_ROLE`) e richiedono i dati dell'attività già inseriti (altrimenti 409 `PROFILE_REQUIRED`).
Creare e modificare box richiede `can_publish` (attività **approvata** e conto di pagamento collegato): altrimenti 403 `SELLER_NOT_READY`. Elenco, dettaglio ed eliminazione restano disponibili.

## Formula del prezzo (RB-04, RB-05)

Importi sempre in **centesimi interi**.

```
prezzo   = listino × (100 − sconto) / 100     arrotondato al centesimo, mezzo verso l'alto
guadagno = prezzo − tariffa                   può essere nullo o negativo
```

Esempio: listino 2000, sconto 65 → prezzo 700; tariffa 150 → guadagno 550. Il prezzo lo decide **sempre il server**: un `price_cents` inviato dal client viene ignorato. I casi di prova stanno in [../contracts/price-cases.json](../contracts/price-cases.json) e sono eseguiti sia dai test PHP sia dai test JavaScript dell'app (`npm test` in `apps/mobile`).

La **tariffa** (`fee_cents`, oggi 150) e i limiti dello sconto (`discount_min_pct` 60, `discount_max_pct` 70) stanno nella tabella `config_params` e si cambiano nel database. Ogni box conserva **la tariffa del momento della creazione** (istantanea): cambiarla dopo non tocca le box già pubblicate.

## Endpoint

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/seller/box-options` | Liste per il modulo: `box_types`, `categories`, `attributes` (con `group`), più `fee_cents`, `discount_min_pct`, `discount_max_pct`, `max_quantity`, `today`. |
| GET | `/seller/boxes?date=YYYY-MM-DD` | Le box del giorno (oggi se omesso), dalla più recente. Risposta: `{ date, can_publish, fee_cents, data: [box] }`. |
| GET | `/seller/boxes/{id}` | Una box del venditore (404 se non è sua). |
| POST | `/seller/boxes` | Crea una box **per oggi**. 201 con la box. |
| PUT | `/seller/boxes/{id}` | Modifica una box di oggi (vedi regole sotto). |
| DELETE | `/seller/boxes/{id}` | Elimina una box senza vendite. 204. |

### Campi di `POST /seller/boxes` (e di `PUT`, tutti facoltativi in modifica)

| Campo | Regola |
|---|---|
| `box_type_id` | Obbligatorio. ID di una tipologia (`box_types`). |
| `category_id` | Obbligatorio. ID di una categoria. |
| `attributes` | Facoltativo. Elenco di codici (`INDOOR`, `TOXIC_PETS`…), max 10, ognuno esistente. |
| `description` | Obbligatorio, 5–1000 caratteri. |
| `list_value_cents` | Obbligatorio, intero 1–1.000.000 (nessun minimo di listino o prezzo oltre 1 centesimo). |
| `discount_pct` | Obbligatorio, intero tra 60 e 70 (limiti da `config_params`). |
| `quantity` | Obbligatorio, intero 1–999. |
| `confirm_low_earning` | Booleano. Serve `true` quando il guadagno è nullo o negativo (vedi sotto). |

### La box

`id`, `offer_date`, `box_type {id, code, label}`, `category {…}`, `attributes [codici]`, `description`, `list_value_cents`, `discount_pct`, `price_cents`, `fee_cents`, `earning_cents` (può essere negativo), `earning_not_positive`, `stock_total`, `stock_available`, `sold`, `sold_out`, `price_locked`, `status`.

## Regole

- **Guadagno nullo o negativo (US-V06 CA4):** se `prezzo ≤ tariffa` il server risponde **409 `LOW_EARNING_CONFIRMATION_REQUIRED`** con il calcolo in `quote` (`price_cents`, `fee_cents`, `earning_cents`…) e **non salva**. Il client mostra l'avviso e rimanda la richiesta con `confirm_low_earning: true`. Vale anche in modifica del prezzo. Nessun addebito al venditore: il guadagno resta negativo nei riepiloghi.
- **Modifica (US-V09):** solo box **di oggi** (altrimenti 409 `BOX_NOT_EDITABLE`).
  - Descrizione, tipologia, categoria e attributi: sempre.
  - `list_value_cents` e `discount_pct`: solo se non c'è stata nessuna vendita; dopo, 409 `PRICE_LOCKED` (rimandare gli stessi valori non è una modifica).
  - `quantity`: può **solo diminuire** (422 `QUANTITY_CANNOT_INCREASE`) e non scendere sotto il numero già venduto (422 `QUANTITY_BELOW_SOLD`). `stock_available = quantity − sold`. Portarla a quanto venduto rende la box esaurita.
- **Eliminazione (US-V10):** solo senza vendite; con ordini 409 `BOX_HAS_ORDERS` (si può solo ridurre la quantità). L'eliminazione è logica: la riga resta nel database.
- **Stock (US-V11):** `sold = stock_total − stock_available`. Oggi lo stock cala solo quando i test lo simulano: il decremento automatico a ogni acquisto arriva con il carrello (giorno 8), con lo stesso blocco di riga già usato qui in modifica per evitare corse.
- **Giorno di vendita:** "oggi" è calcolato in `Europe/Rome` con l'orologio simulato (`php artisan clock advance 24h` in sviluppo).

## Codici di errore

`SELLER_NOT_READY` 403 · `PROFILE_REQUIRED` 409 · `LOW_EARNING_CONFIRMATION_REQUIRED` 409 · `PRICE_LOCKED` 409 · `BOX_NOT_EDITABLE` 409 · `BOX_HAS_ORDERS` 409 · `QUANTITY_CANNOT_INCREASE` 422 · `QUANTITY_BELOW_SOLD` 422 · `NOT_FOUND` 404 · 422 di validazione con `errors` per campo.

## Limiti noti

- **Prezzo zero:** con un listino di pochi centesimi il prezzo può arrivare a 0 (nessun minimo, RB-04). Stripe non accetta pagamenti sotto 0,50 €: il caso va gestito nel pagamento (giorno 8).
- La pubblicazione non controlla se la fascia di ritiro di oggi è già finita; il limite per ordinare (2 ore prima della fine) si applicherà all'acquisto.
- Le box ricorrenti (modelli) sono fuori dall'MVP (roadmap R2).
