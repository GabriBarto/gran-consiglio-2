# API di recensioni, reclami e rimborsi (giorno 12)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.

## Il percorso di un reclamo

```
ordine ritirato ──▶ (entro 24 ore dal ritiro)         casi b, c, d
ordine non ritirato ──▶ (da inizio ritiro fino a fine fascia + 24 ore)   caso a

cliente apre il reclamo ──▶ ordine IN_COMPLAINT, payout HELD
        │
        └─ l'admin decide
              ├─ ACCEPT_FULL ...... rimborso totale  ──▶ ordine REFUNDED_FULL,    payout SKIPPED (o REVERSED se già inviato)
              ├─ ACCEPT_PARTIAL ... rimborso parziale ──▶ ordine REFUNDED_PARTIAL, payout ridotto dello stesso importo
              └─ REJECT ........... nessun rimborso   ──▶ ordine COMPLAINT_REJECTED, payout di nuovo SCHEDULED
```

Il termine (24 ore) si legge dal parametro `complaint_window_hours` di `config_params`. Lo controlla sempre il server.

## Cliente

| Metodo | Percorso | Descrizione |
|---|---|---|
| POST | `/orders/{id}/review` | Recensione dopo il ritiro: una per ordine. |
| POST | `/orders/{id}/complaints` | Apre un reclamo su un proprio ordine. |
| GET | `/orders/{id}` | Ora contiene anche `review`, `can_review`, `complaint`, `complaint_options`. |

### `POST /orders/{id}/review`
Corpo: `{ "rating": 1..5, "comment": "…" }` (commento facoltativo, max 1000 caratteri). `201` con `{ id, rating, comment, created_at }`.
Errori: `409 REVIEW_NOT_ALLOWED` (ordine non ritirato), `409 ALREADY_REVIEWED`, `404` (ordine di un altro), `422` (voto fuori da 1–5).

### `POST /orders/{id}/complaints`
Corpo: `{ "reason": "…", "description": "…" }` (descrizione 10–2000 caratteri, solo testo).

| `reason` | Quando |
|---|---|
| `CLOSED_OR_NOT_DELIVERED` (a) | Ordine **non ritirato**, dall'inizio della fascia di ritiro fino a fine fascia + 24 ore. |
| `VALUE_BELOW_DECLARED` (b) | Ordine **ritirato**, entro 24 ore dal ritiro. |
| `PLANTS_DAMAGED` (c) | Come (b). |
| `NOT_AS_DECLARED` (d) | Come (b). |

Non esiste un reclamo per mancato ritiro né per contenuto "non gradito" (US-C14 CA4). `201` con il reclamo (`status: OPEN`).
Errori: `409 COMPLAINT_EXISTS` (uno solo per ordine), `409 COMPLAINT_NOT_ALLOWED` (ordine mai pagato, già rimborsato, ritiro non ancora iniziato), `409 COMPLAINT_WINDOW_CLOSED`, `422 REASON_NOT_ALLOWED` (es. "negozio chiuso" su un ordine ritirato).

### Nuovi campi di `GET /orders/{id}` e `GET /orders`
```
"review": { "id", "rating", "comment", "created_at" } | null,
"can_review": true,                       // ritirato e non ancora recensito
"complaint": { "id", "status", "reason", "reason_label", "description",
               "decision_note", "refund_cents", "created_at", "decided_at" } | null,
"complaint_options": { "reasons": [ { "code", "label" } ], "until": "2026-06-16T16:00:00+00:00" | null }
```
`complaint_options.reasons` è la lista dei motivi che si possono usare **adesso**: vuota se il termine è scaduto, il reclamo esiste già o l'ordine non lo permette. L'app mostra "Apri un reclamo" solo se non è vuota.

Stati dell'ordine aggiunti: `IN_COMPLAINT`, `REFUNDED_PARTIAL`, `COMPLAINT_REJECTED` (`REFUNDED_FULL` esisteva già per il pagamento tardivo).

## Venditore

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/seller/reviews` | `{ summary: { average, count }, data: [ { id, rating, comment, created_at, order_number, customer: { username } } ] }` (ultime 50). |
| GET | `/seller/complaints` | `{ open, data: [ reclamo + order: { id, number, total_cents } + customer: { username } ] }` (ultimi 50), con esito e motivazione. |

Del cliente compare solo il nome utente. `GET /seller/earnings` ha ora `refunded_cents` per ordine; con un rimborso parziale `earning_cents` è già al netto del rimborso.

## Admin

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/admin/complaints?status=OPEN\|DECIDED` | Coda (default `OPEN`, dal più vecchio) o storico (dal più recente). Contiene anche `open`, il numero da decidere. |
| GET | `/admin/complaints/{id}` | Dettaglio per decidere. |
| POST | `/admin/complaints/{id}/decide` | Decisione. |

Il dettaglio contiene l'ordine (voci con prezzo pagato e **valore di listino dichiarato**, totale, date), il negozio, il cliente (nome utente ed **email**), lo stato del payout (`payout.status`, `payout.amount_cents`, `payout.already_sent`) e `refunded_cents`.

### `POST /admin/complaints/{id}/decide`
```
{ "decision": "ACCEPT_FULL" | "ACCEPT_PARTIAL" | "REJECT",
  "refund_cents": 200,            // solo ACCEPT_PARTIAL: da 1 a totale − 1, in centesimi
  "note": "Motivazione…",         // 5–1000 caratteri, arriva a cliente e venditore per email
  "suspend_seller": true }        // facoltativo: sospende anche il venditore (US-S05 CA2); dopo 3 reclami accolti in 30 giorni scatta comunque da sola (vedi API-venditore.md)
```
Errori: `409 INVALID_STATE` (già deciso), `422 INVALID_REFUND_AMOUNT`, `422` (campi mancanti), `502 PAYMENT_PROVIDER_ERROR` (Stripe non ha risposto: **nulla è stato salvato**, si può ripetere).

## Effetti sul pagamento al venditore

| Decisione | Payout `SCHEDULED`/`HELD` | Payout già `SENT` |
|---|---|---|
| Totale | `SKIPPED` | storno dell'intero importo, poi `REVERSED` |
| Parziale (rimborso R) | importo = `max(0, importo − R)`, torna `SCHEDULED` (a zero verrà saltato) | storno di `min(R, importo)`; l'importo diventa il netto incassato (`REVERSED` se resta zero) |
| Rifiuto | `HELD` → `SCHEDULED` | — |

## Email
- Apertura → al venditore (`ComplaintOpenedMail`).
- Decisione → a cliente **e** venditore (`ComplaintDecisionMail`), con esito, importo e motivazione.
