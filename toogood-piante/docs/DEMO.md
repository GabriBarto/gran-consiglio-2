# Copione della demo (circa 15 minuti)

Racconta l'intero percorso del prodotto: **venditore approvato → cliente compra → ritiro con QR → recensione → reclamo e rimborso → pagamento al venditore → sospensione**. Usa i dati di `DemoSeeder` (giorno 13) e l'orologio simulato per saltare le attese. I comandi vanno lanciati dalla cartella `toogood-piante`.

Chi fa cosa: **1 persona al PC** (pannello admin, Mailpit, terminale) e **2 telefoni** con Expo Go (uno cliente, uno venditore). Con un solo telefono funziona uguale, cambiando account.

## Prima della demo (15 minuti prima, non davanti al pubblico)

1. Docker Desktop acceso, poi database pulito con i dati di prova:
   ```bash
   docker compose up -d
   docker compose exec php php artisan migrate:fresh --seed
   docker compose exec php php artisan db:seed --class=DemoSeeder
   docker compose exec php php artisan clock reset
   ```
2. **Cambia la password dell'admin** (`admin@toogood.test`, password iniziale in `apps/api/.env.example`): al primo accesso la chiede, e davanti al pubblico non deve comparire. Poi apri il pannello: `cd apps/admin && npm run dev` → http://localhost:5173.
3. App mobile: `cd apps/mobile && npx expo start --clear`, aprila sui due telefoni.
4. **Accedi una volta con ogni account** (il codice a 6 cifre arriva su Mailpit, http://localhost:8025): dopo, il telefono viene ricordato e in demo basta la password. Account demo, password `Demo-Password1`:

   | Ruolo | Email |
   |---|---|
   | Cliente | `anna.demo@toogood.test` |
   | Cliente (non ritira mai) | `luca.demo@toogood.test` |
   | Venditore (Bologna, ritiro 17:00–19:00) | `rossi.demo@toogood.test` |
   | Venditore sospeso (Roma) | `sospeso.demo@toogood.test` |
   | Admin | `admin@toogood.test` |

5. Tieni aperte tre finestre: **Mailpit**, **pannello admin** (scheda Reclami), **terminale**.

**Importante sull'orario.** Si ordina fino a 2 ore prima della fine del ritiro: per Vivaio Rossi **fino alle 17:00 (Roma)**. Fai la demo prima di quell'ora; se è più tardi le box risultano "Ordini chiusi" e l'orologio simulato può solo andare avanti, non indietro. Le box del `DemoSeeder` sono di **oggi**: rilancialo se cambia il giorno.

---

## Atto 1 – Un nuovo negozio entra (3 minuti, facoltativo)

*"Un vivaista si iscrive e prima di vendere viene controllato."*

1. **Telefono venditore:** Registrati come venditore (email nuova, accetta i termini). Conferma l'email dal link in Mailpit, poi accedi (codice in Mailpit).
2. Compila i dati dell'attività: P.IVA `IT12345678901` (la verifica è simulata), indirizzo `via, numero, città` reale (es. `Via Rossini 10, Pesaro`: serve internet), orari e fascia di ritiro. Allega un PDF, collega il conto di pagamento.
3. **PC, pannello admin:** scheda **Verifiche** → il nuovo negozio → dettaglio con esito P.IVA e documenti → **Approva**.
4. **Telefono venditore:** *Aggiorna stato* → "Approvata, tutto pronto".

Se si va di fretta, salta l'atto 1 e usa Vivaio Rossi, già approvato.

## Atto 2 – Il cliente trova una box e compra (3 minuti)

*"Il cliente vede i negozi più vicini e prende una box a sorpresa."*

1. **Telefono cliente:** accedi come `anna.demo@toogood.test`. L'app apre la ricerca e chiede la posizione: i negozi sono ordinati per **distanza**. Prova anche la ricerca per città o nome.
2. Apri **Vivaio Rossi**: orari, fascia di ritiro, **orario limite d'ordine**, box con prezzo, risparmio minimo ("almeno X €") e pezzi rimasti.
3. Scegli una box → **Aggiungi al carrello** → **Vai al carrello** → riepilogo con l'**avviso rosso "nessun rimborso"** e la casella obbligatoria → ordine con il **conto alla rovescia di 10 minuti** (la box è tenuta da parte) → **Paga (prova)**.
4. **PC, Mailpit:** arrivano la **conferma al cliente** (con il codice di ritiro) e il **nuovo ordine al venditore**.
5. Ripeti con `luca.demo@toogood.test` (un altro telefono o dopo essere usciti): serve per l'atto 5.

*Se qualcuno chiede: "e se due persone comprano l'ultima box?"* → i test di concorrenza (acquisti e scansioni simultanei) hanno dato sempre lo stock giusto e una sola scansione riuscita.

## Atto 3 – Il ritiro con il QR (3 minuti)

*"Il QR vale solo nella fascia di ritiro e una volta sola."*

1. **Telefono cliente:** apri l'ordine di Anna (I miei ordini): il **QR** e il codice `ABCD-EFGH`, con lo stato "il ritiro non è ancora iniziato".
2. **PC, terminale:** porta l'orologio dentro la fascia (17:00–19:00 Roma). Guarda l'ora e avanza di quanto manca:
   ```bash
   docker compose exec php php artisan clock now
   docker compose exec php php artisan clock advance 390m
   ```
   (Il comando accetta una sola unità: `390m`, non `6h30m`.) Controlla con `clock now` che sia tra le 17:00 e le 19:00 a Roma.
3. **Telefono venditore** (`rossi.demo@toogood.test`): nella home, **Ritiri di oggi** mostra l'ordine "Pagato". **Scansiona il QR di un cliente** → esito verde "Ritiro confermato".
4. Scansiona **lo stesso QR di nuovo** → rosso, "già usato alle HH:MM". (Facoltativo: il codice scritto a mano.)

## Atto 4 – Recensione e reclamo (3 minuti)

*"Dopo il ritiro il cliente può valutare, oppure protestare."*

1. **Telefono cliente:** nell'ordine ritirato, **Com'è andata?** → cinque stelle e un commento. Il venditore la vede nella scheda **Recensioni**; la media compare nella scheda del negozio.
2. Sempre nell'ordine: **Apri un reclamo** (entro 24 ore dal ritiro) → motivo "piante danneggiate" e almeno 10 caratteri di testo.
3. **Telefono venditore:** l'ordine è **In reclamo**; negli **Incassi** l'importo risulta **Sospeso**; in **Reclami** compare il reclamo. In Mailpit c'è l'email al venditore.
4. **PC, pannello admin** → scheda **Reclami** → il reclamo (con valore di listino dichiarato) → **Rimborso parziale** con un importo in euro (mai zero e mai il totale) e una motivazione → conferma. Mostra anche le altre due scelte: rimborso totale e respingi.
5. In Mailpit: l'**esito** a cliente e venditore.

## Atto 5 – Il pagamento al venditore (2 minuti)

*"Il venditore riceve il suo guadagno 24 ore dopo la fine del ritiro, anche per chi non si è presentato."*

1. **PC, terminale:** avanza oltre le 24 ore dalla fine della fascia e fai partire i pagamenti:
   ```bash
   docker compose exec php php artisan clock advance 30h
   docker compose exec php php artisan orders:close-pickup
   docker compose exec php php artisan payouts:run
   ```
2. **Telefono venditore:** **Aggiorna** negli Incassi. Si vede:
   - l'ordine di Anna **Trasferito**, con "Rimborsati … al cliente" e il pagamento già ridotto;
   - l'ordine di Luca **Non ritirato** ma comunque **Trasferito** (nessun rimborso al cliente che non si è presentato);
   - le **tariffe trattenute** (1,50 € a box).
3. Se vuoi mostrare il guadagno negativo: pubblica una box sotto 1,50 € e compra quella; l'ordine mostra "Guadagno −0,50 € (nessun addebito)" e nessun trasferimento.

## Atto 6 – Sospendere un venditore (1 minuto)

*"Se un negozio dà problemi l'admin lo ferma, ma i clienti non restano senza ordine."*

1. **PC, pannello admin:** **Verifiche** → **Approvate** → Vivaio Rossi → **Sospendi…** con un motivo.
2. **Telefono cliente:** il negozio **non compare più** nella ricerca. **Telefono venditore:** vede il motivo e non può pubblicare box, ma gli ordini già pagati si ritirano ancora. In Mailpit: l'email al venditore.
3. **Riattiva** dalla scheda **Sospese**. (`sospeso.demo@…`, già sospeso, mostra lo stesso stato senza fare nulla.)

---

## Dopo la demo: rimettere tutto a posto

```bash
docker compose exec php php artisan clock reset
docker compose exec php php artisan migrate:fresh --seed
docker compose exec php php artisan db:seed --class=DemoSeeder
```
`migrate:fresh` cancella **tutto**, quindi per una seconda demo bisogna rifare gli accessi con il codice (passo 4 di "Prima della demo"). Per una prova veloce senza cancellare, rilancia solo `DemoSeeder` e `clock reset`: porta box fresche senza duplicare gli account.

## Se qualcosa va storto

| Sintomo | Cosa fare |
|---|---|
| "Ordini chiusi" sulle box | Si è oltre l'orario limite (17:00 per Rossi). Compra da un negozio con ritiro più tardi (Verde Milano: fino alle 17:30) o, per la prossima volta, cancella l'orologio con `clock reset` e riparti prima dell'orario |
| Il QR non si accetta ("non ancora iniziato") | L'orologio non è dentro la fascia: `clock now` e avanza ancora |
| "Aggiorna" non cambia nulla | I dati sono quelli di prima: se il pagamento non è partito, rilancia `payouts:run` dopo `clock advance` |
| L'admin risponde 403 | Ha ancora la password iniziale: va cambiata al primo accesso |
| Il codice 2FA non arriva | Mailpit, http://localhost:8025 (il login ha un limite di 5 tentativi al minuto) |
| 502 dall'API | `docker compose restart nginx` |
| La decisione del reclamo dà 502 | Stripe simulato non ha risposto: nulla è stato salvato, si può ripetere |
| L'app non trova il server | Telefono e PC sulla stessa rete; altrimenti `EXPO_PUBLIC_API_URL` (vedi [AVVIO.md](AVVIO.md)) |

**Piano B se l'app non parte:** tutta la logica si può mostrare dal pannello admin, da Mailpit e dalle risposte dell'API (guida: [AVVIO.md](AVVIO.md), "Prova gli ordini e i pagamenti senza app").
