# Giorno 9 – App cliente: carrello, pagamento e storico: perché queste scelte

Obiettivo del giorno: un cliente compra dall'app, dall'inizio alla fine: aggiunge le box al carrello, rivede il riepilogo, accetta l'avviso "nessun rimborso", paga e ritrova l'ordine nello storico. Riferimenti: US-C04…C08 e C11 in `UserStory-v4.md`. Lato server: [API-ordini.md](../API-ordini.md) (giorno 8) più il nuovo `GET /orders`.

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. La logica del carrello è un file a parte, senza schermate
- **Cosa:** `src/cart.js` e `src/orders.js` contengono regole e formati (aggiungere, limiti di stock, un solo negozio, totali, riallineamento, testi d'errore) senza nessuna dipendenza da React Native; le schermate si limitano a mostrarli.
- **Perché:** le schermate non si possono provare senza un telefono, la logica sì: si esegue con `npm test` in un secondo. Le regole delicate (mai oltre lo stock, mai due negozi, risparmio mai negativo) sono quindi coperte da 22 test, e non solo dalla speranza di aver toccato il pulsante giusto.
- **Provato anche contro l'API vera:** uno script ha percorso lo stesso giro dell'app (ricerca, scheda, carrello, ordine, pagamento, storico) con la logica reale dell'app e il server vero, per accorgersi subito di campi che non coincidono. Tutti i passaggi tornavano.

## 2. Il carrello vive in memoria e si svuota cambiando utente
- **Cosa:** `CartProvider` tiene il carrello in memoria; cambiando account o uscendo si azzera. Se si chiude l'app si perde.
- **Perché:** salvarlo su disco richiede lo storage sicuro del telefono, che ha limiti di dimensione, e un carrello perso si rifà in dieci secondi; un carrello che passa da una persona all'altra sullo stesso telefono sarebbe invece un difetto di privacy. Il vero impegno parte comunque solo al pagamento (box riservate 10 minuti).
- **Alternativa:** carrello sul server o su disco. Più comodo, ma da ricontrollare comunque ogni volta.

## 3. Mai oltre lo stock, ma lo stock vero lo decide il server
- **Cosa:** l'app impedisce di superare le box disponibili (pulsante + che si ferma, messaggio "hai preso tutte quelle disponibili"), e quando si apre il carrello lo riallinea con la scheda aggiornata del negozio: toglie le box esaurite o non più ordinabili, riduce le quantità e **dice cosa è cambiato** (anche se il prezzo è cambiato).
- **Perché:** tra "aggiungo" e "pago" possono passare minuti e qualcun altro può prendere l'ultima box. Il controllo dell'app serve a non far sbagliare il cliente; quello del server (blocco delle righe) è l'unico che garantisce davvero. Se il server risponde `OUT_OF_STOCK`, l'app elenca quali box mancano e conferma che non è stato addebitato nulla.
- **Un solo negozio:** aggiungendo una box di un altro negozio l'app chiede conferma e svuota il carrello (US-C04 CA1).

## 4. L'avviso "nessun rimborso" non si può saltare
- **Cosa:** una scheda in rosso con una casella da spuntare; senza, il pulsante di pagamento resta spento. L'app invia comunque `accept_no_refund` e il server rifiuta senza.
- **Perché:** RB-09 e US-C06 lo vogliono evidente e accettato esplicitamente. Il controllo vero è nel server (un altro client non potrebbe saltarlo).

## 5. Il pagamento, in prova, passa dallo stesso codice di quello vero
- **Cosa:** con `stripe-mock` non esistono carte né webhook. Dopo l'ordine l'app mostra il conto alla rovescia dei 10 minuti e un pulsante "Paga (prova)" che chiama `simulate-payment`; il server costruisce un evento e lo manda allo **stesso gestore del webhook di Stripe**. C'è anche "Simula un pagamento fallito" per vedere lo stock che torna.
- **Perché:** per l'ordine cambia tutto come in produzione (stato, email, rilascio delle box). L'unica parte non provata è la schermata di inserimento della carta, che nell'app vera sarebbe del modulo di Stripe (`@stripe/stripe-react-native`) e richiede chiavi di test e una versione dell'app compilata apposta (non basta Expo Go).
- **Se il server non è in modalità prova** l'app lo dice (`payment_simulated`) e non mostra il pulsante di prova: nessun finto pagamento in un ambiente vero.

## 6. Dopo il pagamento si riparte dai negozi, con l'ordine sopra
- **Cosa:** a pagamento riuscito il carrello si svuota e la navigazione viene **sostituita** con "negozi → ordine" (`navigation.reset`), non ampliata.
- **Perché:** tornando indietro con la freccia non si deve ritrovare il checkout con un ordine già pagato, né rischiare di pagare due volte.

## 7. Storico degli ordini: solo ciò che è un ordine per il cliente
- **Cosa:** nuova rotta `GET /orders` (ultimi 50, dal più recente). Non mostra gli ordini scaduti o falliti (mai pagati: per il cliente non sono ordini) ma mostra quelli in attesa (con "fino a che ora sono tue le box"), pagati e rimborsati. La schermata si aggiorna ogni volta che ci si torna.
- **Perché:** uno storico pieno di tentativi scaduti confonderebbe. Una scritta "In attesa di pagamento" per un ordine ancora valido invece serve.

## 8. Cosa non c'è (ancora)
Carta vera (modulo di Stripe), QR di ritiro e stato "ritirato" (giorno 10: la schermata dell'ordine lo annuncia già), carrello salvato su disco, modifica o annullamento di un ordine (il venditore non può annullare e il cliente non ha rimborso: RB-08/RB-09), recensioni (giorno 12).
