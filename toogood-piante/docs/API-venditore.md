# API del venditore e verifica admin (giorno 4)

Base: `http://localhost:8080/api`. Valgono le regole di [API-auth.md](API-auth.md): JSON, `Authorization: Bearer <access_token>`, errori nella forma `{ "code", "message" }`.
Tutte le rotte richiedono un utente autenticato, attivo e con la password già cambiata (`password.fresh`). Quelle `/seller` sono solo per il ruolo `SELLER`, quelle `/admin` solo per `ADMIN` (altrimenti 403 `FORBIDDEN_ROLE`).

## Stati della verifica

```
(nessun profilo)  = INCOMPLETE  (solo come risposta, non è salvato)
PENDING ──approva──▶ APPROVED
   ▲  └────rifiuta──▶ REJECTED ──(reinvia)──▶ PENDING
APPROVED ↔ SUSPENDED   (sospensione: giorno 13)
```

`can_publish` è vero solo con stato `APPROVED` **e** conto Stripe collegato.
I dati dell'attività si modificano solo in `PENDING` e `REJECTED` (altrimenti 403 `PROFILE_LOCKED`).

## Venditore

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/seller` | Stato completo: `status`, `verification_note`, `editable`, `seller`, `documents`, `stripe_connected`, `can_publish`. |
| PUT | `/seller/profile` | Crea o aggiorna i dati. 201 al primo invio (stato `PENDING`), 200 dopo. |
| POST | `/seller/documents` | Carica un documento (`multipart/form-data`, campo `file`). |
| DELETE | `/seller/documents/{id}` | Elimina un proprio documento. |
| POST | `/seller/resubmit` | Dopo un rifiuto, rimette la richiesta in coda. Serve almeno un documento. |
| POST | `/seller/stripe/onboarding` | Crea l'account Connect (una sola volta) e restituisce `{ "url" }` dove completare i dati. |
| POST | `/seller/stripe/sync` | Chiede a Stripe se l'onboarding è completo e aggiorna lo stato. Restituisce lo stato come `GET /seller`. |

### Campi di `PUT /seller/profile`

| Campo | Regola |
|---|---|
| `business_name` | Obbligatorio, max 150 caratteri. |
| `vat_number` | `IT` + 11 cifre. Si accettano anche le sole 11 cifre e gli spazi (vengono normalizzati). Univoca. Verificata con il servizio P.IVA. |
| `phone` | 6–20 cifre/spazi, `+` iniziale facoltativo. |
| `address` | Via, numero e città. Viene trasformato in coordinate; la città è ricavata dal geocoding. |
| `opens_at`, `closes_at` | `HH:MM`; la chiusura è dopo l'apertura. |
| `pickup_from`, `pickup_to` | `HH:MM`; il ritiro sta dentro gli orari di apertura e `pickup_to` è dopo `pickup_from`. |

I servizi esterni si chiamano **solo se P.IVA o indirizzo sono cambiati**.

### Documenti

PDF, JPG o PNG, fino a 5 MB, massimo 5 per venditore. Il tipo è letto dal **contenuto** del file, non dal nome. Sono salvati in uno storage privato con un nome generato dal server; la chiave dell'oggetto non compare mai nelle risposte.

### Codici di errore

| Codice | Stato | Quando |
|---|---|---|
| `VAT_INVALID` | 422 | La P.IVA non risulta valida. |
| `ADDRESS_NOT_FOUND` | 422 | Il geocoding non trova l'indirizzo. |
| `ADDRESS_CITY_MISMATCH` | 422 | L'indirizzo esiste ma non nella città scritta; il messaggio indica dove si trova il più simile. Nulla viene salvato. |
| `GEOCODING_UNAVAILABLE` | 503 | Geocoding in timeout o non raggiungibile: nulla viene salvato, si può riprovare. |
| `PROFILE_REQUIRED` | 409 | Documento o Stripe richiesti prima di compilare i dati. |
| `PROFILE_LOCKED` | 403 | Profilo approvato o sospeso: non modificabile. |
| `NO_DOCUMENTS` | 422 | Reinvio o approvazione senza documenti. |
| `TOO_MANY_DOCUMENTS` | 422 | Più di 5 documenti. |
| `INVALID_STATE` | 409 | Operazione non ammessa nello stato attuale (es. reinvio senza rifiuto, seconda decisione). |
| `STRIPE_UNAVAILABLE` | 502 | Stripe non risponde. |
| `FORBIDDEN_ROLE` | 403 | Ruolo non autorizzato. |

Se il servizio P.IVA è **non disponibile** (errore del servizio, non P.IVA sbagliata) il profilo si salva comunque e il controllo resta `UNAVAILABLE`: lo vede l'admin e decide.

## Admin

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/admin/sellers?status=PENDING` | Coda delle richieste (`PENDING` di default; anche `APPROVED`, `REJECTED`, `SUSPENDED`), dalla più vecchia. Ogni voce ha anche `email`, `username` e `vat_check_status` (`VALID` / `UNAVAILABLE`). |
| GET | `/admin/sellers/{id}` | Dettaglio di una richiesta. |
| GET | `/admin/sellers/{id}/documents/{docId}` | Scarica il documento (solo admin, passa dall'API). |
| POST | `/admin/sellers/{id}/approve` | Approva. Serve almeno un documento. Invia l'email al venditore. |
| POST | `/admin/sellers/{id}/reject` | Rifiuta. Body `{ "note": "..." }` obbligatorio (5–1000 caratteri). Invia l'email con il motivo. |

Si decide solo su richieste `PENDING`; altrimenti 409 `INVALID_STATE`.

## Pagine web di ritorno da Stripe

`GET /stripe/return` e `GET /stripe/refresh` (fuori da `/api`) sono le pagine su cui Stripe riporta il venditore: dicono solo di tornare nell'app. L'app, tornando in primo piano, chiama `POST /seller/stripe/sync`.

## Dati finti in sviluppo

- **Geocoding:** di default è **reale** (OpenStreetMap/Nominatim, limitato all'Italia): vale qualsiasi indirizzo, città o paese, ad esempio `Via Rossini 10, Pesaro` o solo `Corniglio`. L'indirizzo va scritto **via, numero, città** con la virgola: la città è l'ultimo pezzo (CAP, sigla di provincia e "Italia" si ignorano) e il risultato deve trovarsi **in quel comune** (o in una sua frazione/quartiere), altrimenti 422 `ADDRESS_CITY_MISMATCH`. Con la sola città non c'è confronto. Serve internet; senza, risponde 503 `GEOCODING_UNAVAILABLE`. Con `GEOCODING_DRIVER=simulated` si usa WireMock: funzionano solo indirizzi che finiscono con "Milano" o "Roma" e `TIMEOUT` simula un servizio lento.
- **P.IVA:** `IT` + 11 cifre che iniziano con 1–8 sono valide (es. `IT12345678901`, `IT22345678901`); `IT99999999999` servizio non disponibile; quelle che iniziano con 0 o 9 non valide. La P.IVA è univoca: un secondo venditore con la stessa riceve 422 sul campo `vat_number`.
- **Stripe:** con `stripe-mock` l'account risulta sempre con i trasferimenti attivi, quindi il collegamento si completa subito. Il link di onboarding restituito è un indirizzo di esempio di Stripe, non una vera procedura: per provare quella vera servono le chiavi di test e `STRIPE_API_BASE` vuoto.
