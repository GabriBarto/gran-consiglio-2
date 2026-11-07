# Schema logico (relazionale)

Lo schema **logico**: ogni entità dello [schema ER](02-schema-er.md) tradotta in una tabella MySQL, con colonne, tipi, chiavi, vincoli e indici. Il perché di ogni scelta è in [01-scelte-e-tabelle.md](01-scelte-e-tabelle.md).

I dati qui sotto sono stati **letti dal database vivo** (MySQL 8.4.13, motore InnoDB, `utf8mb4_unicode_ci`) interrogando `information_schema`, e confrontati con le migrazioni in `apps/api/database/migrations`. Per rifarli: sezione 7.

## 1. Notazione compatta (algebra relazionale)

Chiave primaria indicata con `*`, chiave esterna con `→ tabella`, valore unico con `UQ`, valore facoltativo (che può essere `NULL`) con `?`.

```
users(id*, username UQ, email UQ, password, role, status, email_verified_at?, must_change_password,
      anonymized_at?, created_at?, updated_at?)

login_challenges(id*, public_id UQ, user_id → users, code_hash, expires_at, attempts, used_at?, created_at?, updated_at?)
refresh_tokens(id*, user_id → users, token_hash UQ, device?, expires_at, revoked_at?, rotated_at?, created_at?, updated_at?)
trusted_devices(id*, user_id → users, token_hash UQ, device?, last_used_at?, expires_at, revoked_at?, created_at?, updated_at?)
password_reset_tokens(email*, token, attempts, created_at?)
personal_access_tokens(id*, tokenable_type, tokenable_id, name, token UQ, abilities?, last_used_at?, expires_at?, created_at?, updated_at?)

sellers(id*, user_id → users UQ, business_name, vat_number UQ, vat_check_status?, phone, address, city,
        latitude, longitude, opens_at, closes_at, pickup_from, pickup_to, stripe_account_id?, stripe_onboarded_at?,
        verification_status, verification_note?, verified_at?, created_at?, updated_at?)
seller_documents(id*, seller_id → sellers, storage_key, original_name, mime_type, size_bytes, created_at?, updated_at?)

box_types(id*, code UQ, label, sort_order)
categories(id*, code UQ, label, sort_order)
plant_attributes(id*, code UQ, label, group, sort_order)
config_params(key*, value, description?, created_at?, updated_at?)

box_offers(id*, seller_id → sellers, offer_date, box_type_id → box_types, category_id → categories, attributes,
           description?, list_value_cents, discount_pct, price_cents, fee_cents, stock_total, stock_available,
           status, created_at?, updated_at?, deleted_at?)
orders(id*, customer_id → users, seller_id → sellers, order_date, status, total_cents, fee_total_cents,
       stripe_payment_intent_id? UQ, no_refund_accepted_at, pickup_window_end_at, paid_at?, picked_up_at?,
       created_at?, updated_at?)
order_items(id*, order_id → orders, box_offer_id → box_offers, quantity, unit_price_cents, unit_fee_cents)
stock_reservations(id*, order_id → orders, box_offer_id → box_offers, quantity, expires_at, released_at?, created_at?, updated_at?)
pickup_tokens(id*, order_id → orders UQ, jti UQ, short_code UQ, valid_from, valid_until, used_at?,
              scanned_by? → users, created_at?, updated_at?)

payouts(id*, order_id → orders UQ, amount_cents, scheduled_at, status, stripe_transfer_id?, sent_at?, created_at?, updated_at?)
reviews(id*, order_id → orders UQ, customer_id → users, seller_id → sellers, rating, comment?, status, created_at?, updated_at?)
complaints(id*, order_id → orders, customer_id → users, reason, description, status, decision_note?, refund_cents,
           decided_by? → users, decided_at?, created_at?, updated_at?)
refunds(id*, order_id → orders, complaint_id? → complaints, amount_cents, stripe_refund_id?, created_at?, updated_at?)
stripe_events(id*, type, processed_at?, created_at?, updated_at?)
```

Tabelle tecniche di Laravel (senza relazioni con quelle di business): `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

---

## 2. Convenzione dei tipi

| Migrazione Laravel | Tipo MySQL |
|---|---|
| `id()` | `BIGINT UNSIGNED AUTO_INCREMENT` (chiave primaria) |
| `foreignId()` | `BIGINT UNSIGNED` + chiave esterna |
| `string(n)` (senza n: 255) | `VARCHAR(n)` |
| `unsignedTinyInteger` / `SmallInteger` / `Integer` | `TINYINT` / `SMALLINT` / `INT` `UNSIGNED` (0–255 / 0–65 535 / 0–4,29 miliardi) |
| `timestamp()` | `TIMESTAMP` (sempre in UTC) |
| `timestamps()` | `created_at`, `updated_at` `TIMESTAMP NULL` |
| `softDeletes()` | `deleted_at TIMESTAMP NULL` |
| `uuid()` | `CHAR(36)` |
| `boolean()` | `TINYINT(1)` |
| `decimal(9, 6)` | `DECIMAL(9,6)` |
| `json()` | `JSON` |
| `time()`, `date()` | `TIME`, `DATE` |

Nelle tabelle sotto `N` = `NOT NULL`, `?` = può essere `NULL`. Le colonne `created_at`/`updated_at` sono `TIMESTAMP NULL` e non sono ripetute.

---

## 3. Identità e accesso

### `users`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK**, auto-increment |
| `username` | VARCHAR(255) | N | **UNIQUE** |
| `email` | VARCHAR(255) | N | **UNIQUE** |
| `password` | VARCHAR(255) | N | hash |
| `role` | VARCHAR(16) | N | valori: `CUSTOMER`, `SELLER`, `ADMIN` · indice |
| `status` | VARCHAR(16) | N | default `ACTIVE` · valori `ACTIVE`, `SUSPENDED`, `DELETED` · indice |
| `email_verified_at` | TIMESTAMP | ? | |
| `must_change_password` | TINYINT(1) | N | default 0 |
| `anonymized_at` | TIMESTAMP | ? | |

### `login_challenges`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `public_id` | CHAR(36) | N | **UNIQUE** (uuid) |
| `user_id` | BIGINT UNSIGNED | N | **FK → users.id** `ON DELETE CASCADE` |
| `code_hash` | VARCHAR(255) | N | |
| `expires_at` | TIMESTAMP | N | indice |
| `attempts` | TINYINT UNSIGNED | N | default 0 |
| `used_at` | TIMESTAMP | ? | |

### `refresh_tokens`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `user_id` | BIGINT UNSIGNED | N | **FK → users.id** `CASCADE` |
| `token_hash` | VARCHAR(64) | N | **UNIQUE** (SHA-256) |
| `device` | VARCHAR(120) | ? | |
| `expires_at` | TIMESTAMP | N | indice |
| `revoked_at` | TIMESTAMP | ? | |
| `rotated_at` | TIMESTAMP | ? | |

### `trusted_devices`
Stessa struttura di `refresh_tokens` (`id`, `user_id` FK `CASCADE`, `token_hash` VARCHAR(64) UNIQUE, `device` VARCHAR(120)?, `expires_at` N con indice, `revoked_at`?) più `last_used_at` TIMESTAMP?.

### `password_reset_tokens`
`email` VARCHAR(255) **PK** · `token` VARCHAR(255) N · `attempts` TINYINT UNSIGNED N default 0 · `created_at` TIMESTAMP?. Nessuna FK (vedi [01](01-scelte-e-tabelle.md)).

### `personal_access_tokens` (Laravel Sanctum)
`id` PK · `tokenable_type` VARCHAR(255) N + `tokenable_id` BIGINT UNSIGNED N (relazione polimorfica, indice composto, **nessuna FK**) · `name` TEXT N · `token` VARCHAR(64) N **UNIQUE** · `abilities` TEXT? · `last_used_at` TIMESTAMP? · `expires_at` TIMESTAMP? con indice.

---

## 4. Venditori

### `sellers`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `user_id` | BIGINT UNSIGNED | N | **FK → users.id** `CASCADE` · **UNIQUE** (relazione 1:1) |
| `business_name` | VARCHAR(255) | N | |
| `vat_number` | VARCHAR(16) | N | **UNIQUE** |
| `vat_check_status` | VARCHAR(16) | ? | `VALID`, `UNAVAILABLE` |
| `phone` | VARCHAR(32) | N | |
| `address` | VARCHAR(255) | N | |
| `city` | VARCHAR(255) | N | indice |
| `latitude` | DECIMAL(9,6) | N | indice composto con `longitude` |
| `longitude` | DECIMAL(9,6) | N | |
| `opens_at`, `closes_at` | TIME | N | orari del negozio (una fascia per tutti i giorni) |
| `pickup_from`, `pickup_to` | TIME | N | fascia di ritiro |
| `stripe_account_id` | VARCHAR(255) | ? | |
| `stripe_onboarded_at` | TIMESTAMP | ? | |
| `verification_status` | VARCHAR(16) | N | default `PENDING` · `PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED` · indice |
| `verification_note` | TEXT | ? | |
| `verified_at` | TIMESTAMP | ? | |

### `seller_documents`
`id` PK · `seller_id` BIGINT UNSIGNED N **FK → sellers.id** `CASCADE` · `storage_key` VARCHAR(255) N · `original_name` VARCHAR(255) N · `mime_type` VARCHAR(100) N · `size_bytes` INT UNSIGNED N.

---

## 5. Dati fissi, offerte e ordini

### `box_types`, `categories`
`id` PK · `code` VARCHAR(32) N **UNIQUE** · `label` VARCHAR(255) N · `sort_order` SMALLINT UNSIGNED N default 0. (Stessa struttura per entrambe. Nessun `timestamps`.)

### `plant_attributes`
Come sopra, più `group` VARCHAR(16) N (`LOCATION`, `TOXICITY`, `LIGHT`, `CARE`).

### `config_params`
`key` VARCHAR(64) **PK** · `value` VARCHAR(255) N · `description` VARCHAR(255)? · `created_at`, `updated_at`.

### `box_offers`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `seller_id` | BIGINT UNSIGNED | N | **FK → sellers.id** `ON DELETE RESTRICT` |
| `offer_date` | DATE | N | |
| `box_type_id` | BIGINT UNSIGNED | N | **FK → box_types.id** (`NO ACTION`) |
| `category_id` | BIGINT UNSIGNED | N | **FK → categories.id** (`NO ACTION`) |
| `attributes` | JSON | N | array di codici di `plant_attributes` |
| `description` | TEXT | ? | facoltativa |
| `list_value_cents` | INT UNSIGNED | N | |
| `discount_pct` | TINYINT UNSIGNED | N | |
| `price_cents` | INT UNSIGNED | N | |
| `fee_cents` | INT UNSIGNED | N | snapshot |
| `stock_total` | SMALLINT UNSIGNED | N | |
| `stock_available` | SMALLINT UNSIGNED | N | **CHECK `chk_box_offers_stock`**: `stock_available <= stock_total` |
| `status` | VARCHAR(16) | N | default `ACTIVE` · `ACTIVE`, `CLOSED` |
| `deleted_at` | TIMESTAMP | ? | cancellazione logica |

### `orders`
| Colonna | Tipo | | Vincolo / default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `customer_id` | BIGINT UNSIGNED | N | **FK → users.id** `RESTRICT` |
| `seller_id` | BIGINT UNSIGNED | N | **FK → sellers.id** `RESTRICT` |
| `order_date` | DATE | N | |
| `status` | VARCHAR(24) | N | indice · vedi macchina a stati in [02](02-schema-er.md) |
| `total_cents` | INT UNSIGNED | N | |
| `fee_total_cents` | INT UNSIGNED | N | |
| `stripe_payment_intent_id` | VARCHAR(255) | ? | **UNIQUE** |
| `no_refund_accepted_at` | TIMESTAMP | N | |
| `pickup_window_end_at` | TIMESTAMP | N | snapshot, UTC |
| `paid_at`, `picked_up_at` | TIMESTAMP | ? | |

### `order_items`
| Colonna | Tipo | | Vincolo |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | **PK** |
| `order_id` | BIGINT UNSIGNED | N | **FK → orders.id** `CASCADE` |
| `box_offer_id` | BIGINT UNSIGNED | N | **FK → box_offers.id** `RESTRICT` |
| `quantity` | SMALLINT UNSIGNED | N | |
| `unit_price_cents` | INT UNSIGNED | N | snapshot |
| `unit_fee_cents` | INT UNSIGNED | N | snapshot |

Nessun `created_at`/`updated_at`.

### `stock_reservations`
`id` PK · `order_id` N **FK → orders.id** `CASCADE` · `box_offer_id` N **FK → box_offers.id** `RESTRICT` · `quantity` SMALLINT UNSIGNED N · `expires_at` TIMESTAMP N (indice) · `released_at` TIMESTAMP? · `created_at`, `updated_at`.

### `pickup_tokens`
`id` PK · `order_id` N **FK → orders.id** `CASCADE`, **UNIQUE** · `jti` VARCHAR(64) N **UNIQUE** · `short_code` VARCHAR(12) N **UNIQUE** · `valid_from`, `valid_until` TIMESTAMP N · `used_at` TIMESTAMP? · `scanned_by` BIGINT UNSIGNED? **FK → users.id** `SET NULL` · `created_at`, `updated_at`.

---

## 6. Denaro e feedback

### `payouts`
`id` PK · `order_id` N **FK → orders.id** `RESTRICT`, **UNIQUE** · `amount_cents` INT UNSIGNED N · `scheduled_at` TIMESTAMP N (indice) · `status` VARCHAR(16) N default `SCHEDULED` (indice; `SCHEDULED`, `HELD`, `SENT`, `SKIPPED`, `REVERSED`) · `stripe_transfer_id` VARCHAR(255)? · `sent_at` TIMESTAMP? · `created_at`, `updated_at`.

### `reviews`
`id` PK · `order_id` N **FK → orders.id** `RESTRICT`, **UNIQUE** · `customer_id` N **FK → users.id** `RESTRICT` · `seller_id` N **FK → sellers.id** `RESTRICT` · `rating` TINYINT UNSIGNED N · `comment` TEXT? · `status` VARCHAR(16) N default `VISIBLE` (`VISIBLE`, `REMOVED`) · indice composto `(seller_id, status)` · `created_at`, `updated_at`.

### `complaints`
`id` PK · `order_id` N **FK → orders.id** `RESTRICT` · `customer_id` N **FK → users.id** `RESTRICT` · `reason` VARCHAR(32) N (`CLOSED_OR_NOT_DELIVERED`, `VALUE_BELOW_DECLARED`, `PLANTS_DAMAGED`, `NOT_AS_DECLARED`) · `description` TEXT N · `status` VARCHAR(16) N default `OPEN` (indice; `OPEN`, `ACCEPTED_FULL`, `ACCEPTED_PARTIAL`, `REJECTED`) · `decision_note` TEXT? · `refund_cents` INT UNSIGNED N default 0 · `decided_by` BIGINT UNSIGNED? **FK → users.id** `SET NULL` · `decided_at` TIMESTAMP? · `created_at`, `updated_at`.

### `refunds`
`id` PK · `order_id` N **FK → orders.id** `RESTRICT` · `complaint_id` BIGINT UNSIGNED? **FK → complaints.id** `SET NULL` · `amount_cents` INT UNSIGNED N · `stripe_refund_id` VARCHAR(255)? · `created_at`, `updated_at`.

### `stripe_events`
`id` VARCHAR(255) **PK** (l'id dell'evento Stripe) · `type` VARCHAR(255) N · `processed_at` TIMESTAMP? · `created_at`, `updated_at`.

---

## 7. Chiavi esterne e regole di cancellazione (riepilogo)

25 chiavi esterne, lette dal database. `RESTRICT` e `NO ACTION` impediscono la cancellazione del padre se esistono figli; `CASCADE` cancella i figli; `SET NULL` azzera il riferimento.

| Tabella figlia | Colonna → tabella padre | Alla cancellazione del padre |
|---|---|---|
| `sellers` | `user_id` → `users` | CASCADE |
| `seller_documents` | `seller_id` → `sellers` | CASCADE |
| `login_challenges`, `refresh_tokens`, `trusted_devices` | `user_id` → `users` | CASCADE |
| `box_offers` | `seller_id` → `sellers` | **RESTRICT** |
| `box_offers` | `box_type_id` → `box_types`, `category_id` → `categories` | NO ACTION (equivale a RESTRICT in InnoDB) |
| `orders` | `customer_id` → `users`, `seller_id` → `sellers` | **RESTRICT** |
| `order_items` | `order_id` → `orders` | CASCADE |
| `order_items` | `box_offer_id` → `box_offers` | **RESTRICT** |
| `stock_reservations` | `order_id` → `orders` | CASCADE |
| `stock_reservations` | `box_offer_id` → `box_offers` | **RESTRICT** |
| `pickup_tokens` | `order_id` → `orders` | CASCADE |
| `pickup_tokens` | `scanned_by` → `users` | SET NULL |
| `payouts` | `order_id` → `orders` | **RESTRICT** |
| `reviews` | `order_id` → `orders`, `customer_id` → `users`, `seller_id` → `sellers` | **RESTRICT** |
| `complaints` | `order_id` → `orders`, `customer_id` → `users` | **RESTRICT** |
| `complaints` | `decided_by` → `users` | SET NULL |
| `refunds` | `order_id` → `orders` | **RESTRICT** |
| `refunds` | `complaint_id` → `complaints` | SET NULL |

**Regola di lettura:** tutto ciò che riguarda soldi o storico (ordini, payout, recensioni, reclami, rimborsi) è protetto da `RESTRICT`; ciò che è solo dettaglio di un padre (righe d'ordine, documenti, codici di accesso, prenotazioni) si cancella a cascata.

## 8. Indici (oltre alle chiavi primarie e alle chiavi esterne)

Gli indici creati automaticamente per le chiavi esterne non sono ripetuti.

| Tabella | Indice | Colonne | Serve per |
|---|---|---|---|
| `users` | `users_email_unique`, `users_username_unique` | email · username | unicità e ricerca al login |
| `users` | `users_role_index`, `users_status_index` | role · status | elenchi per ruolo/stato |
| `sellers` | `sellers_user_id_unique`, `sellers_vat_number_unique` | user_id · vat_number | relazione 1:1 e P.IVA unica |
| `sellers` | `sellers_city_index` | city | ricerca per città |
| `sellers` | `sellers_latitude_longitude_index` | latitude, longitude | prefiltro a riquadro prima del calcolo della distanza |
| `sellers` | `sellers_verification_status_index` | verification_status | la coda dell'admin ("in attesa", "sospese") |
| `box_offers` | `box_offers_offer_date_seller_id_status_index` | offer_date, seller_id, status | "le box attive di oggi di un negozio" |
| `box_types`, `categories`, `plant_attributes` | `*_code_unique` | code | codici stabili e unici |
| `orders` | `orders_seller_id_order_date_index` | seller_id, order_date | ordini di un negozio in un giorno |
| `orders` | `orders_customer_id_created_at_index` | customer_id, created_at | storico di un cliente |
| `orders` | `orders_status_index` | status | comandi periodici sugli stati |
| `orders` | `orders_stripe_payment_intent_id_unique` | stripe_payment_intent_id | un pagamento = un ordine |
| `stock_reservations` | `stock_reservations_expires_at_index` | expires_at | rilascio delle prenotazioni scadute |
| `pickup_tokens` | `pickup_tokens_order_id_unique`, `_jti_unique`, `_short_code_unique` | order_id · jti · short_code | un biglietto per ordine; QR e codice non duplicabili |
| `payouts` | `payouts_order_id_unique` | order_id | un payout per ordine |
| `payouts` | `payouts_scheduled_at_index`, `payouts_status_index` | scheduled_at · status | "i payout da eseguire" |
| `reviews` | `reviews_order_id_unique` | order_id | una recensione per ordine |
| `reviews` | `reviews_seller_id_status_index` | seller_id, status | media e conteggio per negozio |
| `complaints` | `complaints_status_index` | status | la coda dei reclami da decidere |
| `login_challenges` | `…_public_id_unique`, `…_expires_at_index` | public_id · expires_at | ricerca del codice e pulizia |
| `refresh_tokens`, `trusted_devices` | `…_token_hash_unique`, `…_expires_at_index` | token_hash · expires_at | ricerca del token e pulizia |
| `personal_access_tokens` | `…_token_unique`, `…_tokenable_type_tokenable_id_index`, `…_expires_at_index` | token · (tokenable_type, tokenable_id) · expires_at | libreria Sanctum |
| `cache`, `cache_locks` | `…_expiration_index` | expiration | pulizia |
| `jobs`, `failed_jobs` | `jobs_queue_index`, `failed_jobs_uuid_unique`, `failed_jobs_connection_queue_failed_at_index` | queue · uuid · (connection, queue, failed_at) | coda |

## 9. Vincoli di unicità che fanno rispettare le regole di business

| Regola | Come la applica il database |
|---|---|
| Una sola attività per account venditore | `sellers.user_id` UNIQUE |
| Una P.IVA, un venditore | `sellers.vat_number` UNIQUE |
| Un solo biglietto di ritiro per ordine; QR e codice non duplicabili | `pickup_tokens.order_id`, `.jti`, `.short_code` UNIQUE |
| Un solo payout per ordine (mai pagare due volte) | `payouts.order_id` UNIQUE |
| Una sola recensione per ordine | `reviews.order_id` UNIQUE |
| Un pagamento Stripe appartiene a un solo ordine | `orders.stripe_payment_intent_id` UNIQUE |
| Un webhook Stripe non si elabora due volte | `stripe_events.id` (PK) |
| Lo stock non supera il totale | CHECK `chk_box_offers_stock` |
| Non si cancella lo storico dei soldi | `RESTRICT` (sezione 7) |

Le regole che il database **non** applica, ma sono nel codice: un solo reclamo per ordine, voto da 1 a 5, sconto tra 60 % e 70 %, codici di `box_offers.attributes` esistenti, valori ammessi di `status` e `role`.

## 10. Come rigenerare queste informazioni

Con i container accesi (`docker compose up -d`):

```bash
docker compose exec mysql mysql -utoogood -psecret toogood -e "SHOW CREATE TABLE orders\G"
```

Tutte le tabelle e il motore:
```bash
docker compose exec mysql mysql -utoogood -psecret toogood -e "SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema='toogood' ORDER BY table_name;"
```

Le chiavi esterne con la regola di cancellazione:
```bash
docker compose exec mysql mysql -utoogood -psecret toogood -e "SELECT table_name, column_name, referenced_table_name, constraint_name FROM information_schema.key_column_usage WHERE table_schema='toogood' AND referenced_table_name IS NOT NULL ORDER BY 1,2;"
```

Gli indici:
```bash
docker compose exec mysql mysql -utoogood -psecret toogood -e "SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols FROM information_schema.statistics WHERE table_schema='toogood' AND index_name<>'PRIMARY' GROUP BY table_name, index_name, non_unique ORDER BY 1,2;"
```

In alternativa, con l'interfaccia grafica Adminer: `docker compose --profile tools up -d`, poi http://localhost:8081 (server `mysql`, utente `toogood`, password `secret`, database `toogood`).
