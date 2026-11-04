# API degli ordini e dei pagamenti (giorno 8)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.
Le rotte `/orders` sono solo per il ruolo `CUSTOMER` (altrimenti 403 `FORBIDDEN_ROLE`). `POST /webhooks/stripe` è pubblica ma protetta dalla firma di Stripe.

## Il percorso di un ordine

```
carrello (nell'app)
   └─ POST /orders ───────────▶ PENDING_PAYMENT   box tenute da parte per 10 minuti, PaymentIntent creato
                                   │
        pagamento riuscito ────────┼──▶ PAID               (webhook o pagamento simulato) + email a cliente e venditore
        pagamento fallito  ────────┼──▶ PAYMENT_FAILED     box di nuovo disponibili
        10 minuti senza pagare ────┼──▶ EXPIRED            box di nuovo disponibili, PaymentIntent annullato
        nuovo carrello dello stesso cliente ──▶ EXPIRED    (il vecchio ordine in attesa viene sostituito)

   pagamento arrivato quando l'ordine era già EXPIRED o PAYMENT_FAILED ──▶ REFUNDED_FULL (rimborso automatico + email)
```

Il **carrello vive nell'app**: al server arriva soltanto al momento del checkout, e viene sempre ricontrollato (stock, prezzo e orario possono essere cambiati). Gli stati successivi (ritirato, reclami, rimborsi parziali) arrivano nei giorni 10–12.

## Endpoint

| Metodo | Percorso | Descrizione |
|---|---|---|
| POST | `/orders` | Crea l'ordine in attesa di pagamento e tiene da parte le box. 201. Limite: 20 richieste al minuto per utente. |
| GET | `/orders/{id}` | Stato di un **proprio** ordine (404 se è di un altro). L'app lo rilegge dopo il pagamento. |
| POST | `/orders/{id}/simulate-payment` | **Solo sviluppo.** Esegue il pagamento di prova (vedi sotto). 404 con Stripe vero. |
| POST | `/webhooks/stripe` | Riceve gli eventi di Stripe. Verifica la firma; 400 se non valida. |

### `POST /orders` — corpo

| Campo | Regola |
|---|---|
| `items` | Obbligatorio, da 1 a 20 righe `{ box_id, quantity }`. Un `box_id` non può comparire due volte. |
| `items.*.quantity` | Intero da 1 a 999, non oltre lo stock residuo. Non c'è un limite per cliente (RB-07). |
| `accept_no_refund` | Obbligatorio e **vero**: è la casella "se non ritiri la box non è previsto rimborso" (RB-09, US-C06). Senza, 422. |

Risposta 201: l'ordine (vedi sotto) più

```
"payment": { "client_secret": "...", "payment_intent_id": "pi_...", "publishable_key": "...", "simulated": true }
```

L'app completa il pagamento **direttamente con Stripe** usando `client_secret` (i dati della carta non passano mai dai nostri server, US-C07). Con `simulated: true` (sviluppo con stripe-mock) non c'è una carta vera da usare: l'app chiama `simulate-payment`.

### L'ordine

`id`, `number` (es. `TG-000042`), `status`, `order_date`, `total_cents`, `min_saving_cents` (risparmio minimo complessivo), `items [ { box_id, box_type, category, description, quantity, unit_price_cents, line_total_cents, min_saving_cents } ]`, `shop { id, business_name, address, city, pickup_from, pickup_to }`, `pickup_window_end_at`, `reserved_until` (finché è in attesa: fino a quando le box restano tenute da parte), `paid_at`.
La tariffa di servizio e i dati riservati del venditore non compaiono mai.

## Cosa controlla il checkout (e con quale errore)

Tutto in un'unica transazione: o si prenota tutto, o niente.

| Controllo | Esito |
|---|---|
| Una box non esiste o è stata eliminata | 404 `BOX_NOT_FOUND` |
| Una box non è di oggi o non è attiva | 409 `BOX_NOT_AVAILABLE` |
| Box di più negozi | 422 `MIXED_SHOPS` (un carrello, un negozio: US-C04 CA1) |
| Negozio non approvato, sospeso o senza conto | 409 `SHOP_NOT_AVAILABLE` |
| Passato l'orario limite (fine ritiro − 2 ore) | 409 `ORDER_DEADLINE_PASSED` (US-C05) |
| Stock insufficiente anche per una sola riga | 409 `OUT_OF_STOCK` con `items: [ { box_id, requested, available } ]`; **nessuna box prenotata e nessun pagamento creato** (US-C07 CA3) |
| Totale sotto 0,50 € (minimo di Stripe) | 422 `PAYMENT_AMOUNT_TOO_LOW` |
| Stripe non risponde | 502 `STRIPE_UNAVAILABLE`; le box tornano subito disponibili |

Prezzo e tariffa di ogni riga sono **copiati** dalla box al momento dell'ordine (istantanea): se il venditore o l'admin cambiano un valore dopo, l'ordine non cambia.

Un cliente ha **un solo ordine in attesa**: rifare il carrello libera quello precedente e annulla il suo pagamento, così provare più volte non toglie stock agli altri.

## Stock riservato

Le box dell'ordine sono sottratte subito allo stock (`stock_available`) e registrate in `stock_reservations` con scadenza a 10 minuti (`reservation_minutes` in `config_params`). Il comando **`reservations:release`**, pianificato ogni minuto, libera quelle scadute, riporta lo stock e annulla il PaymentIntent. In sviluppo si può provare senza aspettare:

```bash
docker compose exec php php artisan clock advance 11m
docker compose exec php php artisan reservations:release
docker compose exec php php artisan clock reset
```

Un pagamento che arriva pochi secondi **dopo** la scadenza ma **prima** che il comando giri viene onorato (le box sono ancora tenute da parte).

## Webhook di Stripe

- **Firma:** si verifica con `Stripe-Signature` e il segreto `STRIPE_WEBHOOK_SECRET`, sul corpo esatto della richiesta. Senza firma valida, firma vecchia (oltre 5 minuti) o corpo modificato → 400 e nessun effetto.
- **Eventi gestiti:** `payment_intent.succeeded` (ordine → `PAID`, email) e `payment_intent.payment_failed` (ordine → `PAYMENT_FAILED`, stock restituito). Gli altri sono accettati e ignorati.
- **Idempotenza:** l'id dell'evento è salvato in `stripe_events`; lo stesso evento ripetuto risponde `duplicate` e non fa nulla. In più ogni passaggio controlla lo stato dell'ordine con la riga bloccata, quindi anche due consegne simultanee o due eventi diversi per lo stesso pagamento non producono doppie email né doppi rimborsi.
- **Pagamento tardivo:** se arriva `succeeded` per un ordine già `EXPIRED` o `PAYMENT_FAILED`, il pagamento è **rimborsato per intero** (con una chiave di idempotenza, quindi un nuovo tentativo non rimborsa due volte), l'ordine diventa `REFUNDED_FULL` e il cliente riceve un'email.
- **Se Stripe o il rimborso non funzionano:** il webhook risponde 500, l'evento non è segnato come elaborato e Stripe riprova.
- Risposta: `{ "received": true, "outcome": "paid" | "late_refund" | "released" | "ignored" | "unknown" | "duplicate" }`.

## Pagamento simulato (solo sviluppo)

`stripe-mock` non ha carte né genera webhook. Quando è attivo (`PAYMENTS_SIMULATED=true`, o `STRIPE_API_BASE` che punta a stripe-mock e variabile non impostata) `POST /orders/{id}/simulate-payment` costruisce un evento Stripe e lo passa **allo stesso codice del webhook vero**: per l'ordine cambia tutto come in produzione. Corpo facoltativo `{ "outcome": "succeeded" | "failed" }`. Con le API vere di Stripe la rotta non esiste (404). Per provare anche la firma: [AVVIO.md](AVVIO.md).

## Email

| Email | A chi | Quando |
|---|---|---|
| `Ordine TG-… confermato` | cliente | ordine pagato: riepilogo, negozio, indirizzo, fascia di ritiro, istruzioni sul QR, promemoria "nessun rimborso" (US-C08) |
| `Nuovo ordine TG-…` | venditore | ordine pagato: numero, box, fascia di ritiro (US-V12) |
| `Pagamento rimborsato per l'ordine TG-…` | cliente | pagamento tardivo rimborsato |

Partono dopo che lo stato è salvato; se la posta non risponde l'errore va nel log e il pagamento resta valido.

## Concorrenza

Il controllo e il calo dello stock sono un passo unico: le righe delle box si bloccano (`lockForUpdate`, sempre in ordine di id) per tutta la transazione, quindi lo stock non scende mai sotto zero. Provato con 8 e 16 acquisti simultanei sulla stessa box da 3 pezzi: sempre esattamente 3 box tenute da parte. In caso di deadlock di MySQL (esito normale con molti acquisti insieme) la transazione è ripetuta da capo fino a 5 volte.

## Limiti noti

- Il **QR di ritiro** e il **pagamento al venditore** non ci sono ancora (giorni 10 e 11); la conferma lo promette già nell'email.
- Nessun elenco ordini (`GET /orders`): arriva con lo storico del giorno 9.
- Con Stripe vero (non il simulatore) servono le chiavi di test, `STRIPE_API_BASE` vuoto, `STRIPE_PUBLISHABLE_KEY` e il profilo `stripe` di Docker per inoltrare i webhook.
