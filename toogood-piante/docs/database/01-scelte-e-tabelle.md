# Database – perché queste tabelle e questi attributi

Questo documento spiega **come siamo arrivati allo schema**, **perché esiste ogni tabella** e **perché ogni colonna è fatta così**. Gli altri documenti della cartella:

- [02-schema-er.md](02-schema-er.md) – schema concettuale (entità e relazioni)
- [03-schema-logico.md](03-schema-logico.md) – schema logico/relazionale (tabelle, tipi, chiavi, indici)
- [04-funzionamento-e-docker.md](04-funzionamento-e-docker.md) – come funziona il database e come è collegato a Docker

I nomi, i tipi e i vincoli citati qui sono stati **controllati sul database vivo** (MySQL 8.4, `information_schema`), non solo letti dalle migrazioni: 28 tabelle, 25 chiavi esterne, 1 vincolo `CHECK`.

---

## 1. Come ci siamo arrivati

Lo schema non è nato da un disegno fatto a tavolino: è la traduzione, in tabelle, di ciò che le **user story** e le **regole di business** chiedono. Il percorso è stato questo.

### Passo 1 – Leggere le regole e sottolineare i sostantivi
Da `UserStory-v4.md` e `PianoTecnico.md` (sezione 3, "Modello dati") abbiamo estratto le **cose di cui il sistema deve ricordarsi**:

| Frase della specifica | Cosa deve ricordare il database |
|---|---|
| "Registrazione cliente / venditore / admin" | chi è la persona e che ruolo ha → `users` |
| "Il venditore indica P.IVA, indirizzo, orari, fascia di ritiro" | i dati dell'attività → `sellers` |
| "Il venditore carica un documento" | i file allegati → `seller_documents` |
| "Ogni giorno pubblica box con prezzo, sconto e quantità" | l'offerta del giorno → `box_offers` (+ tipologie, categorie, attributi) |
| "Il cliente compra più box dallo stesso negozio" | un acquisto e le sue righe → `orders`, `order_items` |
| "La box è tenuta da parte 10 minuti durante il pagamento" | una prenotazione con scadenza → `stock_reservations` |
| "QR di ritiro monouso" | il biglietto → `pickup_tokens` |
| "Il venditore è pagato 24 ore dopo la fine del ritiro" (RB-11) | un pagamento programmato → `payouts` |
| "Recensione dopo il ritiro", "reclamo entro 24 ore", "rimborso deciso dall'admin" | `reviews`, `complaints`, `refunds` |
| "Login con codice a 6 cifre via email" (RB-17) | i codici monouso → `login_challenges` |
| "Tariffa 1,50 €, sconto 60–70 %, 24 ore…" (RB-05, RB-11) | parametri modificabili → `config_params` |

### Passo 2 – Dai sostantivi alle entità, dalle entità alle tabelle
Per ogni sostantivo ci siamo chiesti tre cose:
1. **È una cosa con vita propria?** (un ordine sì; il "prezzo di una riga" no, è un attributo.)
2. **Quante ne esistono per ciascuna delle altre?** Questo decide le chiavi esterne e i vincoli di unicità (vedi [02-schema-er.md](02-schema-er.md)).
3. **Cambia nel tempo, e dobbiamo ricordare com'era?** Se sì, si copia il valore (snapshot): vedi sezione 2.

### Passo 3 – Normalizzare, poi rompere le regole solo con un motivo
Siamo partiti dalla **terza forma normale** (ogni informazione in un solo posto) e abbiamo derogato **solo dove c'era una ragione scritta**. Le deroghe sono quattro, tutte elencate nella sezione 2.

### Passo 4 – Aggiungere ciò che serve per far rispettare le regole al database
Una regola scritta solo nel codice si rompe al primo bug. Dove era possibile, l'abbiamo trasformata in un **vincolo del database**: unicità (un solo payout per ordine, un solo QR per ordine, una sola recensione per ordine), `CHECK` sullo stock, chiavi esterne con `RESTRICT` sullo storico dei soldi.

### Passo 5 – Disegnare gli indici dalle domande che l'app fa davvero
Un indice per ogni ricerca frequente: "le box di oggi di questo negozio", "gli ordini di questo negozio in questo giorno", "le prenotazioni scadute", "i payout da eseguire". Niente indici "per sicurezza".

### Passo 6 – Farlo il giorno 1, correggere con migrazioni piccole
Le tabelle di business sono state progettate **tutte insieme il giorno 1** (migrazioni `2026_10_28_1000xx`), anche quelle che sarebbero servite solo ai giorni 11–12: cambiare lo schema a metà progetto è ciò che fa più danni. Da allora sono state aggiunte **quattro migrazioni**, tutte additive (una è la tabella standard di una libreria, tre sono nostre):

| Data | Migrazione | Perché |
|---|---|---|
| 2026-10-28 | `…_create_personal_access_tokens_table` | token di accesso brevi di Laravel Sanctum (giorno 2) |
| 2026-11-03 | `add_checks_to_sellers_table` | all'admin serve vedere l'esito del controllo P.IVA (`vat_check_status`) e quando Stripe ha confermato l'onboarding (`stripe_onboarded_at`): scoperto scrivendo l'onboarding (giorno 4) |
| 2026-11-04 | `create_trusted_devices_table` | richiesta emersa provando l'app: chiedere il codice 2FA a ogni accesso era fastidioso, quindi si ricorda il dispositivo (giorno 5) |
| 2026-11-04 | `make_box_description_optional` | decisione di prodotto: la descrizione della box è facoltativa (giorno 6) |

Delle tre nostre: **1 tabella nuova per un'esigenza imprevista, 2 colonne aggiunte, 1 colonna resa facoltativa**. Il resto dello schema, compresi payout, recensioni, reclami e rimborsi dei giorni 11–13, **non ha richiesto nessuna migrazione**: le tabelle c'erano già.

### Cosa il piano prevedeva e non abbiamo creato
`PianoTecnico.md` elencava anche `PickupSchedule`, `ScheduleException`, `BoxTemplate`, `ComplaintPhoto`, `AuditLog`, `EmailLog`. Non esistono ancora, volutamente, perché le funzioni corrispondenti sono fuori dall'MVP (roadmap):

| Tabella prevista | Funzione rinviata | Cosa c'è al suo posto |
|---|---|---|
| `PickupSchedule`, `ScheduleException` | orari per giorno, ferie, festivi (R2) | quattro colonne `time` su `sellers`: una sola fascia valida tutti i giorni |
| `BoxTemplate` | box ricorrenti (R2) | il venditore ricrea la box ogni giorno |
| `ComplaintPhoto` | foto nei reclami (R4) | solo testo |
| `AuditLog` | storico generale delle azioni (R4) | `decided_by`/`decided_at` su `complaints`, `verified_at` su `sellers` |
| `EmailLog` | registro delle email inviate | le email si leggono in Mailpit |

---

## 2. Principi che valgono per tutto lo schema

| Principio | Cosa significa | Perché |
|---|---|---|
| **Soldi in centesimi interi** | colonne `*_cents` di tipo intero; sconto intero in percentuale | i numeri decimali producono errori di arrotondamento (0,1 + 0,2 ≠ 0,3); con gli interi i conti tornano sempre. Esiste anche un file di casi condiviso (`contracts/price-cases.json`) che prova la formula in PHP e in JavaScript |
| **Snapshot al momento dell'acquisto** | la tariffa è copiata in `box_offers.fee_cents`; prezzo e tariffa nelle righe d'ordine; la fine del ritiro in `orders.pickup_window_end_at` | se domani la tariffa cambia, gli ordini già fatti restano verificabili e i payout già calcolati non si spostano (US-S07 CA1) |
| **Stati come testo** (`status`, `role`, …) | `varchar` con valori scritti nei commenti, non `ENUM` MySQL | aggiungere uno stato (`IN_COMPLAINT`, `HELD`) non richiede di modificare la tabella; il costo è che il database non impedisce un valore inventato: lo impediscono le costanti nei modelli |
| **Date in UTC, calcoli in `Europe/Rome`** | il server MySQL gira con `--default-time-zone=+00:00`; il fuso italiano si applica solo per decidere "oggi" e le fasce | evita errori al cambio ora legale/solare (testato il 29 marzo e il 25 ottobre) |
| **Chiavi surrogate** | `id` numerico autoincrementale ovunque, più colonne `unique` per ciò che è unico "nel mondo reale" (email, P.IVA, codice) | gli id non cambiano mai; email e P.IVA sì, nel caso peggiore |
| **`RESTRICT` sullo storico, `CASCADE` sui dettagli** | non si può cancellare un venditore, un'offerta o un utente che hanno ordini, recensioni, reclami o payout; si cancellano a cascata solo righe d'ordine, documenti, codici di accesso | la contabilità non deve poter sparire per un errore; i dettagli senza il padre non hanno senso |
| **`SET NULL` sui "chi l'ha fatto"** | `scanned_by`, `decided_by`, `refunds.complaint_id` | se l'utente sparisce, la decisione resta; solo l'autore diventa sconosciuto |
| **Segreti mai in chiaro** | codici 2FA e token salvati come hash (`code_hash`, `token_hash`) | chi legge il database non può usarli |

### Le quattro deroghe alla terza forma normale
| Deroga | Dato duplicato | Perché lo teniamo |
|---|---|---|
| `orders.total_cents`, `fee_total_cents` | sono la somma delle righe | letture veloci e prova di quanto è stato addebitato al cliente anche se le righe cambiassero |
| `orders.seller_id` | si potrebbe ricavare da righe → box → negozio | un ordine è sempre di **un solo** negozio e quasi tutte le domande ("gli ordini di oggi del mio negozio") filtrano per negozio |
| `reviews.seller_id` | si ricava da `order_id` | la media di un negozio si calcola con un solo indice `(seller_id, status)` senza giungere tre tabelle |
| `box_offers.attributes` (JSON) | elenco di codici invece di una tabella ponte | vedi sotto |

**Perché gli attributi delle piante sono un JSON e non una tabella ponte** (`box_offer_attribute`): sono 10 codici fissi, si leggono sempre insieme alla box e **non si filtra ancora per attributo** (i filtri sono nella roadmap, R3). Con la tabella ponte avremmo avuto più join per niente. Il costo è dichiarato: il database non verifica che i codici esistano (lo fa il codice, con un test) e, quando arriveranno i filtri, si potrà migrare a una tabella ponte senza cambiare l'API.

---

## 3. Le tabelle, gruppo per gruppo

La legenda `NN` significa "obbligatorio", `UQ` "unico", `FK` "chiave esterna".

### 3.1 Identità e accesso

#### `users` – chi usa il sistema
Una sola tabella per clienti, venditori e admin, distinti dal ruolo. **Perché non tre tabelle:** tutti fanno login allo stesso modo, hanno email e password; i dati propri del venditore stanno a parte (`sellers`), e il cliente non ha dati propri oltre a quelli di accesso.

| Colonna | Perché |
|---|---|
| `username` UQ | è il nome che il venditore vede del cliente. **Privacy:** al venditore non si mostra mai l'email, solo questo |
| `email` UQ | identifica l'account e riceve i codici; unica perché è l'unico recapito |
| `password` | solo l'**hash** (Laravel, `BCRYPT_ROUNDS=12`), mai la password |
| `role` (index) | `CUSTOMER` \| `SELLER` \| `ADMIN`: decide cosa si può fare |
| `status` (index) | `ACTIVE` \| `SUSPENDED` \| `DELETED`: blocca un account senza cancellarlo |
| `email_verified_at` | nullo = email non ancora confermata; conserva anche *quando* |
| `must_change_password` | l'admin creato dal seeder ha una password nota: al primo accesso è obbligato a cambiarla |
| `anonymized_at` | per la cancellazione GDPR: si anonimizzano i dati personali ma non si cancellano gli ordini (US-C16, roadmap R1) |

#### `login_challenges` – i codici a 6 cifre della 2FA
Il codice è monouso, scade in 10 minuti e ha 5 tentativi. **Perché una tabella** e non la cache: deve sopravvivere a un riavvio e deve poter essere conteggiato.

| Colonna | Perché |
|---|---|
| `public_id` (uuid, UQ) | identificativo che l'app rimanda insieme al codice; indovinarlo non serve senza il codice |
| `user_id` FK→`users` (cascade) | a chi appartiene; se l'utente sparisce spariscono i suoi codici |
| `code_hash` | il codice a 6 cifre è salvato **come hash**, mai in chiaro |
| `expires_at` (index) | i codici scaduti si ripuliscono con una ricerca per data |
| `attempts` | contatore dei tentativi sbagliati (limite 5) |
| `used_at` | una volta usato non vale più (monouso) |

#### `refresh_tokens` – tenere l'accesso senza richiedere ogni volta la password
L'access token dura 15 minuti; il refresh token serve a ottenerne uno nuovo. È **monouso con rotazione**: ogni rinnovo lo sostituisce.

| Colonna | Perché |
|---|---|
| `token_hash` (64, UQ) | hash SHA-256 del token: nel database non c'è nulla di spendibile |
| `device` | nome del dispositivo, per il logout "solo di questo telefono" |
| `expires_at` (index) | scadenza |
| `revoked_at` | logout o cambio password |
| `rotated_at` | valorizzato quando il token è stato **sostituito**: se qualcuno lo presenta di nuovo è un **riuso** e indica un furto, quindi si chiude tutta la catena |

#### `trusted_devices` – ricordare il dispositivo (aggiunta il 4 novembre)
Dopo aver superato il codice, il telefono riceve un `device_token`: ai login successivi basta la password. Stessa struttura di `refresh_tokens` (`token_hash`, `device`, `expires_at`, `revoked_at`) più `last_used_at`, perché la validità è **90 giorni dall'ultimo uso**, non dalla creazione. Cambio o reset password azzerano tutti i dispositivi ricordati.

#### `password_reset_tokens` – recupero password
Struttura standard di Laravel, con in più `attempts`. La chiave primaria è **l'email**: ne esiste al massimo una richiesta attiva per indirizzo, e la nuova sostituisce la vecchia. Non ha chiave esterna verso `users` perché la richiesta di reset può arrivare per un'email che non esiste e la risposta dell'API deve essere identica (per non rivelare quali email sono registrate).

#### `personal_access_tokens` – gli access token (Laravel Sanctum)
Tabella standard della libreria. `tokenable_type` + `tokenable_id` formano una relazione **polimorfica** (il token può appartenere a qualunque modello), per questo **non ha chiave esterna**. `token` è l'hash, `expires_at` è indicizzato.

### 3.2 Venditori

#### `sellers` – l'attività
**Perché separata da `users`:** solo i venditori hanno P.IVA, indirizzo e orari; metterli in `users` lascerebbe decine di colonne vuote per ogni cliente. Il legame è **uno a uno** (`user_id` unico): un utente venditore ha una sola attività.

| Colonna | Perché |
|---|---|
| `user_id` UQ FK→`users` (cascade) | l'unicità rende la relazione 1:1 |
| `business_name`, `phone`, `address` | i dati che il cliente vede nella scheda del negozio (telefono e P.IVA **non** escono mai dall'API pubblica: c'è un test) |
| `vat_number` (16, UQ) | la P.IVA è unica al mondo: una sola attività per P.IVA |
| `vat_check_status` | `VALID` \| `UNAVAILABLE`: l'esito del controllo automatico, mostrato all'admin. Se il servizio di verifica è giù il profilo si salva comunque e decide l'admin |
| `city` (index) | ricerca per città |
| `latitude`, `longitude` `decimal(9,6)` | coordinate con 6 decimali (≈ 11 cm) ricavate dal geocoding dell'indirizzo. Indice composto `(latitude, longitude)` per il prefiltro a riquadro |
| `opens_at`, `closes_at`, `pickup_from`, `pickup_to` (`time`) | **un'unica fascia per tutti i giorni** (orari per giorno: R2). `pickup_to` decide anche fino a quando si ordina (due ore prima) |
| `stripe_account_id` | il conto Stripe Connect dove arriva il guadagno |
| `stripe_onboarded_at` | quando Stripe ha confermato che il conto può ricevere trasferimenti; con `verification_status = APPROVED` decide se si può pubblicare (`can_publish`) |
| `verification_status` (index) | `PENDING` → `APPROVED` \| `REJECTED` → `PENDING`; `APPROVED` ↔ `SUSPENDED` |
| `verification_note` | motivo del rifiuto o della sospensione, mostrato al venditore |
| `verified_at` | quando l'admin ha approvato |

**Perché coordinate decimali e non un tipo spaziale:** la distanza si calcola con `ST_Distance_Sphere(POINT(lng, lat), POINT(:lng, :lat))` direttamente in SQL, che restituisce metri. Il tipo spaziale con SRID 4326 inverte l'ordine delle coordinate e avrebbe introdotto errori sottili; due colonne `decimal` sono semplici e sufficienti per qualche migliaio di negozi.

#### `seller_documents` – i documenti caricati
Il **file non è nel database**: sta nello storage privato (S3Mock in sviluppo). Qui c'è solo il riferimento: `storage_key` (la chiave dell'oggetto), `original_name`, `mime_type`, `size_bytes`. **Perché:** un database pieno di file diventa lento e difficile da salvare; con la chiave si scarica il file solo quando serve (l'admin lo apre con un'autenticazione). Cascade: senza venditore il documento non ha senso.

### 3.3 Dati fissi e configurazione

#### `box_types`, `categories`, `plant_attributes` – i cataloghi
Elenchi che cambiano di rado: taglia della box (piccola/media/grande), categoria (grasse, da fiore, aromatiche…) e caratteristiche delle piante (da interno, tossica per gli animali, pieno sole, cura facile…).
- **Perché tabelle e non costanti nel codice:** in futuro un pannello admin potrà modificarle (R6) senza rilasciare codice.
- `code` (UQ): il nome **stabile** usato dal codice e nel JSON delle box (`INDOOR`); `label` è il testo mostrato e si può correggere senza rompere nulla.
- `sort_order`: l'ordine in cui compaiono.
- `plant_attributes.group`: `LOCATION` \| `TOXICITY` \| `LIGHT` \| `CARE`, per raggrupparli nel modulo.
- Si riempiono con `CatalogSeeder`, rieseguibile senza duplicati.

#### `config_params` – i numeri di business modificabili
`key` (chiave primaria testuale), `value`, `description`. Vi si leggono: tariffa (`fee_cents` = 150), sconto minimo e massimo (60/70), ore limite d'ordine (2), ritardo del payout (24), termine dei reclami (24), soglia di sospensione automatica (3 reclami in 30 giorni), minuti di prenotazione (10).
- **Perché:** la specifica dice più volte "configurabile senza rilasciare nuovo codice". Per la demo basta portare `payout_delay_hours` a pochi minuti.
- Il valore è `varchar`: i parametri sono pochi e si convertono quando si leggono; evita una colonna per tipo.
- Il seeder usa `insertOrIgnore`: **non sovrascrive** un valore cambiato a mano (c'è un test).

### 3.4 Offerte e ordini

#### `box_offers` – la box del giorno
La riga è "il negozio X offre, **in questa data**, N box di questo tipo a questo prezzo". La data fa parte dell'offerta: ogni giorno si crea una nuova riga.

| Colonna | Perché |
|---|---|
| `seller_id` FK (restrict) | di chi è; non si cancella un negozio con offerte |
| `offer_date` | il giorno; con il negozio e lo stato forma l'indice più usato |
| `box_type_id`, `category_id` FK | taglia e categoria dai cataloghi (nessun `ON DELETE`: i cataloghi non si cancellano) |
| `attributes` (json) | codici di `plant_attributes`, es. `["INDOOR","TOXIC_PETS"]` (vedi deroga sopra) |
| `description` (text, **facoltativa**) | testo libero sul contenuto; senza testo resta `NULL` |
| `list_value_cents` | il valore di listino dichiarato: serve a calcolare lo sconto e, dopo un reclamo, a verificare se "il valore è inferiore al dichiarato" |
| `discount_pct` (0–255, in pratica 60–70) | sconto intero in percentuale |
| `price_cents` | prezzo finale **calcolato dal server** (listino × (100 − sconto) / 100) e salvato, non ricalcolato a ogni lettura |
| `fee_cents` | **snapshot** della tariffa al momento della creazione |
| `stock_total`, `stock_available` | totale pubblicato e quanto ne resta |
| `status` | `ACTIVE` \| `CLOSED` |
| `deleted_at` (softDeletes) | un'offerta eliminata sparisce dall'elenco ma resta leggibile dagli ordini che la citano |

- **Vincolo `chk_box_offers_stock`**: `stock_available <= stock_total`. È l'ultima barriera: se un bug provasse a "restituire" più box di quante ne esistono, il database rifiuta. (Che lo stock non scenda sotto zero lo garantisce già il tipo `unsigned`: un valore negativo è un errore del database.)
- **Indice `(offer_date, seller_id, status)`:** la domanda più frequente è "le box attive di oggi di questo negozio" (scheda negozio, elenco del venditore). L'ordine delle colonne parte dalla data perché la ricerca per distanza filtra prima per "oggi".

#### `orders` – un acquisto presso un solo negozio
| Colonna | Perché |
|---|---|
| `customer_id`, `seller_id` FK (restrict) | chi compra e da chi: lo storico non si cancella |
| `order_date` | il giorno dell'ordine (= giorno di ritiro); indicizzato con il negozio per l'elenco "ordini di oggi" |
| `status` (index) | `PENDING_PAYMENT`, `PAID`, `PICKED_UP`, `NOT_PICKED_UP`, `IN_COMPLAINT`, `REFUNDED_FULL`, `REFUNDED_PARTIAL`, `COMPLAINT_REJECTED`, `EXPIRED`, `PAYMENT_FAILED`. 24 caratteri bastano per il più lungo (18) |
| `total_cents`, `fee_total_cents` | quanto ha pagato il cliente e quanto di tariffa (deroga: derivabili dalle righe) |
| `stripe_payment_intent_id` (UQ) | collega l'ordine al pagamento su Stripe; **unico** così un webhook non può essere agganciato a due ordini. È nullo finché il pagamento non è creato (MySQL ammette più `NULL` in una colonna unica) |
| `no_refund_accepted_at` (**NOT NULL**) | la prova che il cliente ha accettato "nessun rimborso" (RB-09): senza non si può nemmeno creare l'ordine |
| `pickup_window_end_at` | snapshot della fine del ritiro in UTC: il comando di fine fascia e il payout lavorano su questa data senza ricalcolare orari |
| `paid_at`, `picked_up_at` | quando è stato pagato e ritirato |
| indice `(customer_id, created_at)` | lo storico del cliente, dal più recente |
| indice `status` | i comandi periodici cercano "gli ordini pagati la cui fascia è finita" |

#### `order_items` – le righe dell'ordine
Risolve il rapporto **molti a molti** tra ordini e box (un ordine contiene più box; una box finisce in molti ordini). `quantity`, `unit_price_cents`, `unit_fee_cents`: prezzo e tariffa sono **copiati** dalla box al momento dell'acquisto. Senza timestamp (la riga nasce e vive con l'ordine). `order_id` cascade; `box_offer_id` restrict (non si cancella una box venduta).

#### `stock_reservations` – tenere da parte le box durante il pagamento
Il cliente ha 10 minuti per pagare. Senza questa tabella due persone potrebbero pagare l'ultima box.
`quantity`, `expires_at` (index: il comando di scadenza cerca "le prenotazioni scadute"), `released_at` (nullo = ancora attiva; valorizzato quando lo stock è tornato disponibile, così non si restituisce due volte). Il decremento dello stock avviene in modo atomico nella stessa transazione della prenotazione.

#### `pickup_tokens` – il biglietto di ritiro
| Colonna | Perché |
|---|---|
| `order_id` UQ (cascade) | un solo biglietto per ordine |
| `jti` (64, UQ) | l'identificativo casuale scritto nel QR; il QR contiene solo numero ordine e `jti`, firmati: nessun dato personale |
| `short_code` (12, UQ) | il codice alternativo `ABCD-EFGH` che il venditore può digitare |
| `valid_from`, `valid_until` | la fascia di ritiro del giorno |
| `used_at` | nullo = non ancora usato; la scansione lo imposta con un aggiornamento condizionato (`WHERE used_at IS NULL`), perciò con 16 scansioni simultanee ne riesce una sola |
| `scanned_by` FK→`users` (set null) | chi ha scansionato; se quel venditore sparisce, il ritiro resta |

### 3.5 Denaro e feedback

#### `payouts` – il pagamento al venditore
Un record per ordine pagato (`order_id` unico, restrict): `amount_cents` = Σ `max(0, prezzo − tariffa)` per box, `scheduled_at` (fine ritiro + 24 ore; index), `status` (index: `SCHEDULED`, `HELD`, `SENT`, `SKIPPED`, `REVERSED`), `stripe_transfer_id`, `sent_at`.
**Perché separato dall'ordine:** ha un proprio ciclo di vita (programmato, sospeso, inviato, stornato) e un'altra scadenza. Un `amount_cents` mai negativo riflette la regola "al venditore non si addebita nulla" (RB-05); il guadagno negativo si legge dal confronto prezzo−tariffa, non da questo campo.

#### `reviews` – recensioni
`order_id` **unico**: una recensione per ordine, garantito dal database. `rating` (1–5, controllato dalla validazione dell'API: `between:1,5`), `comment` facoltativo, `status` (`VISIBLE` \| `REMOVED`, per la futura moderazione). `seller_id` è duplicato apposta (vedi deroghe); indice `(seller_id, status)` per media e conteggio.

#### `complaints` – reclami
`reason` (4 casi ammessi: negozio chiuso o box non consegnata, valore inferiore al dichiarato, piante danneggiate, caratteristiche non rispettate), `description`, `status` (`OPEN`, `ACCEPTED_FULL`, `ACCEPTED_PARTIAL`, `REJECTED`; index perché l'admin lavora sulla coda "da decidere"), `decision_note`, `refund_cents`, `decided_by` (set null), `decided_at`.
**Nota onesta:** il database *non* impedisce due reclami sullo stesso ordine (`order_id` non è unico); la regola "uno per ordine" è nel codice (`ComplaintService`), che blocca la riga dell'ordine per evitare che due richieste simultanee passino entrambe. Si poteva rendere unico, ma lo schema è del giorno 1 e il caso "riaprire un reclamo" non era ancora deciso.

#### `refunds` – i rimborsi eseguiti
Una riga per rimborso effettivamente creato su Stripe: `amount_cents`, `stripe_refund_id`, `order_id`, `complaint_id` (nullo ammesso, set null). **Perché non basta `complaints.refund_cents`:** il reclamo registra la *decisione*, `refunds` il *fatto contabile* avvenuto su Stripe; sono due momenti e il secondo può fallire (l'API risponde 502 e non salva nulla). Oggi solo i reclami creano righe; `complaint_id` nullo prepara il caso di rimborsi senza reclamo.

#### `stripe_events` – l'idempotenza dei webhook
`id` (**l'id dell'evento Stripe come chiave primaria**), `type`, `processed_at`. Stripe può inviare lo stesso webhook più volte: l'inserimento con chiave duplicata fallisce, e così un pagamento non conferma due volte l'ordine né un rimborso parte due volte.

### 3.6 Tabelle tecniche di Laravel
| Tabella | A cosa serve |
|---|---|
| `migrations` | quali migrazioni sono state eseguite e in che lotto |
| `cache`, `cache_locks` | la cache sta nel database (`CACHE_STORE=database`): contiene, tra l'altro, lo **spostamento dell'orologio simulato** |
| `jobs`, `job_batches`, `failed_jobs` | la coda dei lavori (`QUEUE_CONNECTION=database`). È predisposta e c'è un container `queue` che la ascolta, ma **oggi nessun flusso ci mette lavori** (le email partono subito): le tabelle restano vuote |

Le sessioni **non** usano il database (`SESSION_DRIVER=array`): l'API non ha sessioni, solo token. Per questo non esiste una tabella `sessions`.

---

## 4. Le decisioni più importanti, in sintesi

1. **Tutto lo schema il giorno 1**, poi solo modifiche additive: 4 migrazioni nei giorni successivi (3 nostre).
2. **Soldi in centesimi interi** e **snapshot** di prezzo, tariffa e fine ritiro.
3. **Le regole importanti sono vincoli del database**, non solo codice: un payout, un QR, una recensione per ordine; stock mai oltre il totale; webhook mai elaborato due volte.
4. **Lo storico non si cancella** (`RESTRICT`), i segreti non si salvano in chiaro (hash).
5. **Deroghe dichiarate** alla terza forma normale, ognuna con il suo motivo; l'unica con un costo reale è il JSON degli attributi.
6. **Cosa non c'è** è scritto: orari per giorno, box ricorrenti, foto nei reclami, audit log.
