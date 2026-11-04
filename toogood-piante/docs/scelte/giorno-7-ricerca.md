# Giorno 7 – Ricerca per distanza e scheda negozio: perché queste scelte

Obiettivo del giorno (Traguardo 1): un visitatore o un cliente trova i negozi vicino a sé e vede le loro box; chi non ha un account può registrarsi e tornare alla box che guardava. Riferimenti: US-N01…N05, C02, C03 in `UserStory-v4.md`. Endpoint: [API-negozi.md](../API-negozi.md).

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Ricerca e scheda pubbliche
- **Cosa:** `GET /shops` e `GET /shops/{id}` non richiedono l'accesso.
- **Perché:** la storia N01 parte da "utente non registrato": chi arriva deve poter guardare prima di decidere di iscriversi. Il rischio è esporre dati: per questo le risposte sono costruite a mano (`ShopResource`) con solo i campi pubblici, e un test controlla che partita IVA, telefono, email e identificativo Stripe non compaiano mai.
- **Limite di richieste:** 60 al minuto per IP, per non lasciare una rotta aperta a chiunque senza freni.

## 2. Solo negozi che possono vendere
- **Cosa:** compaiono i negozi approvati **e** con il conto di pagamento collegato; gli altri danno 404 anche se si conosce l'ID.
- **Perché:** un cliente non deve vedere negozi dove non potrebbe comprare, né ottenere informazioni su venditori ancora in verifica o rifiutati. È la stessa regola `can_publish` del giorno 4/6, applicata in lettura.

## 3. La distanza si calcola nel database, con le coordinate salvate al giorno 4
- **Cosa:** `ST_Distance_Sphere` di MySQL sulle coordinate decimali del venditore; ordinamento per distanza; `radius_km` con prefiltro per riquadro sulle colonne indicizzate.
- **Perché:** le coordinate si calcolano **una volta**, quando il venditore salva l'indirizzo (giorno 4); la ricerca non chiama nessun servizio esterno. Il database ordina molti negozi senza portarli tutti in memoria. L'ordine delle coordinate in MySQL è (longitudine, latitudine): un errore classico, quindi il test confronta con una formula di Haversine scritta a parte.
- **Alternativa:** tipo spaziale con indice (`SRID 4326`): più scalabile ma con le insidie dell'ordine degli assi; il piano tecnico lo rimanda a quando i negozi saranno molti.
- **Dove:** [ShopController.php](../../apps/api/app/Http/Controllers/ShopController.php).

## 4. "Almeno una box disponibile oggi", con il filtro per vedere anche gli altri
- **Cosa:** di default un negozio compare solo se ha una box di oggi, non eliminata, con stock > 0. `include_empty=1` mostra anche gli altri (US-N01 CA2). I dati di sintesi (box e unità disponibili, prezzo minimo) si calcolano in un'unica query con una sottoquery raggruppata, non con una query per negozio.
- **Perché:** con N negozi, una query per ciascuno ne farebbe N+1: la lista resterebbe lenta con la crescita.

## 5. Ricerca per nome e città: "contiene", senza maiuscole né accenti
- **Cosa:** confronto `LIKE '%testo%'` con la collation del database (non distingue maiuscole e accenti); `%` e `_` scritti dall'utente sono trattati come testo.
- **Perché:** è la forma più semplice che soddisfa il caso comune ("forli" → "Forlì"). Senza l'escape, scrivere `%` troverebbe tutti i negozi.
- **Non fatto (voluto):** tolleranza ai refusi (CA4 di N01): la roadmap la colloca nella fase R3, e per l'MVP il piano chiede la lista semplice.

## 6. L'orario limite d'ordine è deciso dal server
- **Cosa:** `orderable` e `order_deadline` vengono dal server: fine della fascia di ritiro di oggi meno 2 ore, nel fuso Europe/Rome e con l'orologio simulato. Il servizio `OrderWindow` sarà riusato dal carrello (giorno 8).
- **Perché:** se l'app lo calcolasse da sola dipenderebbe dall'orologio e dal fuso del telefono: chi ha l'ora sbagliata ordinerebbe fuori tempo. L'orario è mostrato come testo già pronto (`17:00`), senza conversioni sul telefono.
- **Dove:** [OrderWindow.php](../../apps/api/app/Services/Boxes/OrderWindow.php).

## 7. Il visitatore atterra sulla ricerca; l'acquisto porta all'accesso e poi riporta alla box
- **Cosa:** l'app si apre sull'elenco dei negozi (prima si apriva sul login). Su una box ordinabile il visitatore vede "Accedi per acquistare": l'app **salva su disco** negozio e box e apre il login. Dopo il primo accesso da cliente apre la scheda di quel negozio con la box evidenziata (US-N03, N04). Se nel frattempo la box è esaurita, eliminata o oltre l'orario limite, un messaggio lo dice (N04 CA2).
- **Perché su disco:** tra la registrazione e il primo accesso c'è la conferma dell'email, e l'app può essere chiusa o riavviata. In memoria l'intenzione si perderebbe. Scade dopo 3 giorni e vale solo per i clienti (un venditore o un admin che entra non viene portato a una box).
- **Dove:** [pendingBox.js](../../apps/mobile/src/pendingBox.js), [App.js](../../apps/mobile/App.js).

## 8. La posizione è chiesta all'avvio, ma non è obbligatoria
- **Cosa:** all'apertura l'app chiede il permesso; se è negato o la posizione non è disponibile si cerca per città o nome, con un pulsante per riprovare (N01 CA1). Il permesso è solo "mentre usi l'app" e la posizione non viene mai salvata dal server: viaggia solo come parametro della ricerca.
- **Perché:** la posizione è un dato personale; il server la usa per ordinare e non la memorizza.

## 9. Cosa non c'è (ancora)
Vista su mappa (CA3 di N01, roadmap R3), filtri per categoria e prezzo, ricerca tollerante ai refusi, paginazione, carrello e acquisto (giorno 8: la scheda mostra già prezzo, risparmio e stock, ma il pulsante di acquisto per il cliente arriva col carrello), recensioni reali (giorno 12).
