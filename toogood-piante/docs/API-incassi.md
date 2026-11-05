# API degli incassi e dei pagamenti ai venditori (giorno 11)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.

## Il percorso del denaro

```
cliente paga ──▶ incassa la piattaforma (Stripe)
     │
     └─ ordine PAID ──▶ nasce il payout  SCHEDULED, da eseguire a  fine fascia di ritiro + 24 ore
                              │
                              ├─ reclamo aperto ............. HELD      (sospeso fino alla decisione)
                              ├─ rimborso totale ............ SKIPPED
                              ├─ importo 0 (guadagno ≤ 0) ... SKIPPED   (nessun addebito al venditore)
                              └─ altrimenti ................. SENT      (trasferimento Stripe Connect)
```

Il ritardo (24 ore) si legge dal parametro `payout_delay_hours` della tabella `config_params`: cambiandolo nel database ha effetto subito, senza rilasciare codice. Il payout vale sia per gli ordini **ritirati** sia per quelli **non ritirati** (RB-11).

## Quanto viene trasferito

Per ogni box dell'ordine: `max(0, prezzo − tariffa)` × quantità, **sommato**. Una box in perdita non riduce il guadagno delle altre: una box a 1,00 € (tariffa 1,50 €) conta 0, non −0,50 €. Prezzo e tariffa sono quelli copiati nell'ordine al momento dell'acquisto.

## Endpoint

| Metodo | Percorso | Chi | Descrizione |
|---|---|---|---|
| GET | `/seller/earnings` | venditore | Riepilogo e elenco (ultimi 100 ordini) degli incassi del negozio. |

Non ci sono endpoint per far partire un pagamento: lo fa il comando `payouts:run` (sotto).

### `GET /seller/earnings`

```
{
  "summary": {
    "orders": 3,
    "gross_cents": 2800,        // incassato dai clienti
    "fees_cents": 600,          // tariffe trattenute dalla piattaforma
    "earning_cents": 2200,      // prezzo − tariffa; PUÒ essere negativo
    "transferred_cents": 550,   // payout SENT
    "pending_cents": 550,       // payout SCHEDULED
    "held_cents": 1100          // payout HELD, o SCHEDULED con ordine in reclamo
  },
  "data": [
    { "order_id", "number", "order_date", "order_status",
      "gross_cents", "fee_cents", "earning_cents",
      "payout_cents",           // quanto verrà (o è stato) trasferito, mai negativo
      "payout_status",          // SCHEDULED | HELD | SENT | SKIPPED | REVERSED
      "scheduled_at", "sent_at" }
  ]
}
```

- Gli ordini **rimborsati per intero** non compaiono né nei totali né nell'elenco.
- Il guadagno negativo (`earning_cents < 0`) si mostra com'è, ma `payout_cents` è 0 e non si addebita nulla (RB-05, US-V16 CA3).
- Dati del cliente: nessuno (nemmeno il nome utente); un test controlla che non compaiano.
- Errori: 403 per chi non è venditore, 409 `PROFILE_REQUIRED` se il profilo non è compilato.

## Il comando `payouts:run`

`php artisan payouts:run`, pianificato **ogni 10 minuti**.

1. Programma i payout degli ordini pagati che non ne hanno ancora uno (ordini precedenti al giorno 11).
2. Esegue quelli `SCHEDULED` con `scheduled_at` raggiunto, uno alla volta, con riga bloccata e stato ricontrollato.
3. Esce con codice 1 se almeno un trasferimento è fallito (Stripe non risponde): quel payout resta `SCHEDULED` e riprova alla prossima esecuzione; gli altri non si fermano.

Il trasferimento usa `idempotency_key = payout-<id>`: se il comando si ripete dopo un errore di rete, Stripe restituisce lo stesso trasferimento invece di pagare due volte.

In sviluppo (vedi [AVVIO.md](AVVIO.md)): `clock advance 26h`, `orders:close-pickup`, `payouts:run`, `clock reset`.
