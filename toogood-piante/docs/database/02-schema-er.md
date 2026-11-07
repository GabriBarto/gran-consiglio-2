# Schema ER (entità–relazioni)

Lo schema **concettuale** del database: le entità, come sono collegate e con quali cardinalità. I tipi di dato, le chiavi e gli indici sono nello schema logico: [03-schema-logico.md](03-schema-logico.md). Il perché di ogni scelta è in [01-scelte-e-tabelle.md](01-scelte-e-tabelle.md).

> **Come vedere i diagrammi.** Sono scritti in **Mermaid**: GitHub li disegna da solo aprendo questo file. In VS Code serve l'estensione "Markdown Preview Mermaid Support"; in alternativa si incolla il blocco su https://mermaid.live. Il codice sotto ogni diagramma è il sorgente: si modifica come testo.

## Come leggere la notazione

| Simbolo | Significato |
|---|---|
| `\|\|--o\|` | uno a (zero o uno) |
| `\|\|--o{` | uno a (zero o molti) |
| `\|\|--\|{` | uno a (uno o molti) |
| `PK` / `FK` / `UK` | chiave primaria / chiave esterna / valore unico |

Leggere sempre da sinistra a destra: `users ||--o| sellers : "ha"` = "un utente ha **al più un** venditore; un venditore appartiene a **esattamente un** utente".

---

## 1. Schema completo

Mostra tutte le entità di business e le loro relazioni. Per non appesantire il disegno, per ogni entità sono indicati solo gli attributi più significativi; l'elenco completo è nello schema logico.

```mermaid
erDiagram
    users ||--o| sellers : "gestisce (se venditore)"
    users ||--o{ orders : "compra (cliente)"
    users ||--o{ reviews : "scrive"
    users ||--o{ complaints : "apre"
    users |o--o{ complaints : "decide (admin)"
    users |o--o{ pickup_tokens : "scansiona (venditore)"
    users ||--o{ login_challenges : "riceve codici"
    users ||--o{ refresh_tokens : "ha"
    users ||--o{ trusted_devices : "ricorda"

    sellers ||--o{ seller_documents : "allega"
    sellers ||--o{ box_offers : "pubblica"
    sellers ||--o{ orders : "riceve"
    sellers ||--o{ reviews : "riceve"

    box_types ||--o{ box_offers : "classifica"
    categories ||--o{ box_offers : "classifica"
    plant_attributes }o..o{ box_offers : "descrive (via JSON)"

    box_offers ||--o{ order_items : "venduta in"
    box_offers ||--o{ stock_reservations : "tenuta da parte in"

    orders ||--|{ order_items : "contiene"
    orders ||--o{ stock_reservations : "prenota"
    orders ||--o| pickup_tokens : "ha il biglietto"
    orders ||--o| payouts : "genera"
    orders ||--o| reviews : "ottiene"
    orders ||--o{ complaints : "puo avere"
    orders ||--o{ refunds : "puo avere"
    complaints ||--o{ refunds : "porta a"

    users {
        bigint id PK
        string username UK
        string email UK
        string role "CUSTOMER SELLER ADMIN"
        string status "ACTIVE SUSPENDED DELETED"
    }
    sellers {
        bigint id PK
        bigint user_id FK,UK
        string business_name
        string vat_number UK
        decimal latitude
        decimal longitude
        time pickup_from
        time pickup_to
        string verification_status "PENDING APPROVED REJECTED SUSPENDED"
    }
    seller_documents {
        bigint id PK
        bigint seller_id FK
        string storage_key
    }
    box_types {
        bigint id PK
        string code UK
    }
    categories {
        bigint id PK
        string code UK
    }
    plant_attributes {
        bigint id PK
        string code UK
        string group
    }
    box_offers {
        bigint id PK
        bigint seller_id FK
        date offer_date
        bigint box_type_id FK
        bigint category_id FK
        json attributes
        int price_cents
        int fee_cents "snapshot"
        int stock_total
        int stock_available
    }
    orders {
        bigint id PK
        bigint customer_id FK
        bigint seller_id FK
        string status
        int total_cents
        string stripe_payment_intent_id UK
        timestamp pickup_window_end_at "snapshot"
    }
    order_items {
        bigint id PK
        bigint order_id FK
        bigint box_offer_id FK
        int quantity
        int unit_price_cents "snapshot"
        int unit_fee_cents "snapshot"
    }
    stock_reservations {
        bigint id PK
        bigint order_id FK
        bigint box_offer_id FK
        timestamp expires_at
        timestamp released_at
    }
    pickup_tokens {
        bigint id PK
        bigint order_id FK,UK
        string jti UK
        string short_code UK
        timestamp used_at
        bigint scanned_by FK
    }
    payouts {
        bigint id PK
        bigint order_id FK,UK
        int amount_cents
        timestamp scheduled_at
        string status "SCHEDULED HELD SENT SKIPPED REVERSED"
    }
    reviews {
        bigint id PK
        bigint order_id FK,UK
        bigint customer_id FK
        bigint seller_id FK
        tinyint rating
    }
    complaints {
        bigint id PK
        bigint order_id FK
        bigint customer_id FK
        string reason
        string status "OPEN ACCEPTED_FULL ACCEPTED_PARTIAL REJECTED"
        bigint decided_by FK
    }
    refunds {
        bigint id PK
        bigint order_id FK
        bigint complaint_id FK
        int amount_cents
        string stripe_refund_id
    }
    login_challenges {
        bigint id PK
        bigint user_id FK
        string code_hash
    }
    refresh_tokens {
        bigint id PK
        bigint user_id FK
        string token_hash UK
    }
    trusted_devices {
        bigint id PK
        bigint user_id FK
        string token_hash UK
    }
```

### Le relazioni, una per una

| Relazione | Cardinalità | Come è garantita | Significato |
|---|---|---|---|
| `users` – `sellers` | 1 : 0..1 | `sellers.user_id` unico | un account venditore ha una sola attività; i clienti e gli admin non ne hanno |
| `users` – `orders` (cliente) | 1 : N | FK `orders.customer_id` | un cliente fa molti ordini |
| `sellers` – `orders` | 1 : N | FK `orders.seller_id` | un negozio riceve molti ordini; **ogni ordine è di un solo negozio** |
| `sellers` – `box_offers` | 1 : N | FK `box_offers.seller_id` | un negozio pubblica molte offerte (una o più per giorno) |
| `box_types`, `categories` – `box_offers` | 1 : N | FK | ogni offerta ha una taglia e una categoria |
| `plant_attributes` – `box_offers` | **N : M** | **nessuna FK**: array JSON `box_offers.attributes` | molte caratteristiche per box, molte box per caratteristica (vedi nota) |
| `orders` – `box_offers` | **N : M** | tabella `order_items` | un ordine contiene più box; una box finisce in più ordini |
| `orders` – `order_items` | 1 : 1..N | FK `order_items.order_id` (cascade) | un ordine ha **almeno una** riga (regola dell'API) |
| `orders` – `stock_reservations` | 1 : N | FK | una prenotazione per ogni box dell'ordine |
| `orders` – `pickup_tokens` | 1 : 0..1 | `order_id` unico | il biglietto esiste solo per gli ordini pagati |
| `orders` – `payouts` | 1 : 0..1 | `order_id` unico | un solo pagamento al venditore per ordine, creato al pagamento |
| `orders` – `reviews` | 1 : 0..1 | `order_id` unico | una sola recensione per ordine |
| `orders` – `complaints` | 1 : 0..N nel DB, **0..1 per regola** | FK; unicità imposta dal codice con riga bloccata | vedi nota |
| `orders` – `refunds`, `complaints` – `refunds` | 1 : N | FK (`complaint_id` può essere nullo) | un reclamo accolto genera un rimborso |
| `users` – `pickup_tokens` (scansione) | 1 : N, opzionale | FK `scanned_by` nullable | chi ha letto il QR |
| `users` – `complaints` (decisione) | 1 : N, opzionale | FK `decided_by` nullable | quale admin ha deciso |
| `users` – `login_challenges`, `refresh_tokens`, `trusted_devices` | 1 : N | FK (cascade) | i codici e i token di accesso dell'utente |
| `sellers` – `seller_documents` | 1 : N | FK (cascade) | i documenti caricati |

**Nota sulla N : M con `plant_attributes`.** È l'unica relazione concettuale che il database non rappresenta con una tabella ponte: l'elenco dei codici sta in una colonna JSON. La scelta e il suo costo sono spiegati in [01-scelte-e-tabelle.md](01-scelte-e-tabelle.md) (sezione 2). Nel disegno è tratteggiata (`..`) per ricordarlo.

**Nota sui reclami.** Il modello concettuale dice "un ordine ha al più un reclamo"; lo schema fisico ammette di più e la regola è nel codice. È l'unica cardinalità dove schema e regola non coincidono.

---

## 2. Lo stesso schema, diviso per area

Per leggerlo (o presentarlo) a pezzi.

### 2.1 Identità e accesso

```mermaid
erDiagram
    users ||--o| sellers : "e' anche venditore"
    users ||--o{ login_challenges : "codici 2FA"
    users ||--o{ refresh_tokens : "rinnovo accesso"
    users ||--o{ trusted_devices : "dispositivi ricordati"
    users {
        bigint id PK
        string username UK
        string email UK
        string password "hash"
        string role
        string status
        boolean must_change_password
    }
    login_challenges {
        uuid public_id UK
        string code_hash
        timestamp expires_at
        tinyint attempts
        timestamp used_at
    }
    refresh_tokens {
        string token_hash UK
        timestamp revoked_at
        timestamp rotated_at "riuso = furto"
    }
    trusted_devices {
        string token_hash UK
        timestamp last_used_at
        timestamp expires_at
    }
```

Fuori da queste relazioni (e quindi senza FK) ci sono due tabelle di accesso: `password_reset_tokens` (chiave = email) e `personal_access_tokens` (relazione polimorfica di Sanctum).

### 2.2 Dal venditore all'offerta

```mermaid
erDiagram
    users ||--o| sellers : "puo essere"
    sellers ||--o{ seller_documents : "allega"
    sellers ||--o{ box_offers : "pubblica ogni giorno"
    box_types ||--o{ box_offers : "taglia"
    categories ||--o{ box_offers : "categoria"
    plant_attributes }o..o{ box_offers : "attributi (JSON)"
    sellers {
        bigint id PK
        string vat_number UK
        decimal latitude
        decimal longitude
        time opens_at
        time closes_at
        time pickup_from
        time pickup_to
        string stripe_account_id
        string verification_status
    }
    box_offers {
        bigint id PK
        date offer_date
        json attributes
        int list_value_cents
        int discount_pct
        int price_cents
        int fee_cents
        int stock_total
        int stock_available
        string status
    }
```

### 2.3 Acquisto, prenotazione e ritiro

```mermaid
erDiagram
    users ||--o{ orders : "compra"
    sellers ||--o{ orders : "vende"
    orders ||--|{ order_items : "contiene"
    box_offers ||--o{ order_items : "venduta in"
    orders ||--o{ stock_reservations : "tiene da parte"
    box_offers ||--o{ stock_reservations : "tenuta da parte in"
    orders ||--o| pickup_tokens : "biglietto QR"
    users |o--o{ pickup_tokens : "scansionato da"
    orders {
        bigint id PK
        date order_date
        string status
        int total_cents
        int fee_total_cents
        string stripe_payment_intent_id UK
        timestamp no_refund_accepted_at
        timestamp pickup_window_end_at
        timestamp paid_at
        timestamp picked_up_at
    }
    order_items {
        int quantity
        int unit_price_cents
        int unit_fee_cents
    }
    stock_reservations {
        int quantity
        timestamp expires_at
        timestamp released_at
    }
    pickup_tokens {
        string jti UK
        string short_code UK
        timestamp valid_from
        timestamp valid_until
        timestamp used_at
    }
```

### 2.4 Denaro, recensioni e reclami

```mermaid
erDiagram
    orders ||--o| payouts : "pagamento al venditore"
    orders ||--o| reviews : "recensione"
    orders ||--o{ complaints : "reclamo"
    orders ||--o{ refunds : "rimborsi"
    complaints ||--o{ refunds : "porta a"
    sellers ||--o{ reviews : "valutato"
    users ||--o{ reviews : "scrive"
    users ||--o{ complaints : "apre"
    users |o--o{ complaints : "decide (admin)"
    payouts {
        int amount_cents "mai negativo"
        timestamp scheduled_at
        string status
        string stripe_transfer_id
        timestamp sent_at
    }
    reviews {
        tinyint rating
        text comment
        string status
    }
    complaints {
        string reason
        text description
        string status
        int refund_cents
        timestamp decided_at
    }
    refunds {
        int amount_cents
        string stripe_refund_id
    }
```

Tabelle **senza relazioni** con le altre, perché sono registri a sé:

| Tabella | Cos'è |
|---|---|
| `config_params` | parametri di business (chiave testuale) |
| `stripe_events` | id degli eventi Stripe già elaborati (idempotenza) |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | tabelle tecniche di Laravel |

---

## 3. Come cambia lo stato di un ordine (e delle tabelle collegate)

Non è un diagramma ER, ma serve a capire perché alcune tabelle esistono. Ogni freccia è una modifica a una o più righe.

```mermaid
stateDiagram-v2
    [*] --> PENDING_PAYMENT: crea orders, order_items, stock_reservations
    PENDING_PAYMENT --> PAID: pagamento riuscito, crea pickup_tokens e payouts
    PENDING_PAYMENT --> EXPIRED: 10 minuti, libera lo stock
    PENDING_PAYMENT --> PAYMENT_FAILED: pagamento fallito, libera lo stock
    PAID --> PICKED_UP: scansione, imposta used_at
    PAID --> NOT_PICKED_UP: fine fascia senza scansione
    PICKED_UP --> IN_COMPLAINT: apre complaints, payout HELD
    NOT_PICKED_UP --> IN_COMPLAINT: solo caso negozio chiuso
    IN_COMPLAINT --> REFUNDED_FULL: crea refunds, payout SKIPPED
    IN_COMPLAINT --> REFUNDED_PARTIAL: crea refunds, payout ridotto
    IN_COMPLAINT --> COMPLAINT_REJECTED: payout di nuovo SCHEDULED
    EXPIRED --> REFUNDED_FULL: pagamento arrivato in ritardo
    PAYMENT_FAILED --> REFUNDED_FULL: pagamento arrivato in ritardo
```

Gli stati terminali sono `PICKED_UP`, `NOT_PICKED_UP` (se nessuno protesta), `REFUNDED_FULL`, `REFUNDED_PARTIAL`, `COMPLAINT_REJECTED`, `EXPIRED` e `PAYMENT_FAILED`. Il payout, a sua volta, passa per `SCHEDULED → SENT`, oppure `HELD`, `SKIPPED`, `REVERSED` (storno dopo un reclamo accolto a trasferimento già inviato).
