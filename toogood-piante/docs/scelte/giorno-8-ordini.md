# Giorno 8 – Ordini, stock riservato e pagamenti: perché queste scelte

Obiettivo del giorno: un cliente trasforma il carrello in un ordine, le box restano sue per qualche minuto, il pagamento si conclude con Stripe e arrivano le email. Riferimenti: US-C04…C08, RB-07, RB-09, RB-16 in `UserStory-v4.md`. Endpoint: [API-ordini.md](../API-ordini.md). Per la roadmap questo giorno è il **backend**; carrello, checkout e storico nell'app sono il giorno 9.

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Il carrello vive nell'app; il server vede solo l'ordine
- **Cosa:** nessuna tabella "carrello": `POST /orders` riceve righe (box, quantità) e crea direttamente l'ordine in attesa.
- **Perché:** il carrello non è un impegno finché non si paga; tenerlo sul server vorrebbe dire gestire carrelli abbandonati. Soprattutto: qualunque cosa arrivi viene **ricontrollata** da capo (stock, orario, prezzo), perché tra "aggiungi" e "paga" può essere cambiato tutto.

## 2. Stock riservato: sottratto subito, restituito alla scadenza
- **Cosa:** al checkout le box sono tolte da `stock_available` e registrate in `stock_reservations` con scadenza a 10 minuti; il comando `reservations:release` (ogni minuto) le restituisce se l'ordine non è pagato e annulla il PaymentIntent.
- **Perché:** mentre il cliente digita la carta nessun altro può prendergli l'ultima box (US-C07 CA1), e se non paga lo stock torna disponibile (CA2). Non esiste uno stato intermedio "forse venduta": lo stock mostrato agli altri è sempre quello vero.
- **Alternativa:** controllare lo stock solo al pagamento. Più semplice, ma si rischia di incassare una box che non c'è più e di dover rimborsare (CA3 chiede l'opposto: nessun addebito se lo stock finisce).

## 3. Controllo e calo dello stock sono un passo solo
- **Cosa:** le righe delle box si bloccano (`SELECT … FOR UPDATE`, sempre in ordine di id) per tutta la transazione; il controllo "ce n'è abbastanza?" e il calo avvengono mentre nessun altro può toccarle. Se manca anche una sola riga, la transazione si annulla e non resta nulla (niente prenotazioni a metà, niente pagamento creato).
- **Perché:** con due clienti che comprano l'ultima box nello stesso istante, "leggo, controllo, poi scrivo" venderebbe due volte. Bloccando prima, il secondo aspetta e poi trova lo stock a zero. L'ordine di blocco uguale per tutti riduce i deadlock.
- **Provato dal vivo:** 8 e 16 acquisti simultanei su una box da 3 pezzi → sempre esattamente 3 box tenute da parte, mai sotto zero.

## 4. Un deadlock trovato provando davvero
- **Cosa:** il primo test dal vivo con 8 acquisti simultanei ha dato 3 ordini, 4 rifiuti e **un errore 500**: un deadlock di MySQL (errore 1213). Lo stock era giusto, ma un cliente avrebbe visto un errore.
- **Causa:** la ricerca degli ordini in attesa dello stesso cliente usava `FOR UPDATE`, che blocca anche l'**intervallo** dell'indice; due clienti diversi si bloccavano a vicenda tra quell'intervallo e le righe delle box.
- **Correzione:** niente blocco su quella ricerca (basta il blocco della singola riga, con ricontrollo dello stato) e la transazione si ripete da capo fino a 5 volte se MySQL segnala un deadlock (esito normale con molta concorrenza). I test automatici sequenziali non l'avrebbero mai trovato: serve la prova con richieste parallele.

## 5. Un solo ordine in attesa per cliente
- **Cosa:** rifare il carrello libera l'ordine in attesa precedente e ne annulla il pagamento.
- **Perché:** senza questa regola, chi prova dieci volte lascia dieci prenotazioni da 10 minuti e svuota il negozio senza comprare nulla.

## 6. Il webhook è l'unica verità sul pagamento, e va verificato
- **Cosa:** l'ordine diventa pagato solo quando arriva `payment_intent.succeeded` con firma valida (segreto del webhook, corpo esatto, firma non più vecchia di 5 minuti). Quello che dice l'app non conta.
- **Perché:** la rotta è pubblica (la chiama Stripe), quindi chiunque potrebbe provare a "pagare" un ordine con una richiesta finta. Senza firma valida non succede nulla; un corpo modificato dopo la firma è rifiutato.

## 7. Idempotenza a due livelli
- **Cosa:** (a) l'id di ogni evento è salvato in `stripe_events` e un evento ripetuto è ignorato; (b) ogni cambio di stato controlla comunque lo stato dell'ordine con la riga bloccata.
- **Perché:** Stripe ripete gli eventi (reti lente, nostri errori) e può inviarne due diversi per lo stesso pagamento. Il solo id non basta con consegne simultanee; il solo stato non lascia traccia. Insieme: niente doppie email, niente doppi rimborsi, niente stock restituito due volte.

## 8. Pagamento tardivo: rimborso automatico
- **Cosa:** se il pagamento arriva dopo che le box sono state liberate, l'ordine **non** viene confermato: si rimborsa per intero (chiave di idempotenza, quindi un nuovo tentativo non rimborsa due volte) e si avvisa il cliente.
- **Perché:** nel frattempo le box possono essere state vendute a un altro: confermare l'ordine significherebbe venderle due volte. Se Stripe non risponde al rimborso, il webhook risponde 500 e Stripe riprova: l'evento non va perso.
- **Eccezione voluta:** un pagamento arrivato pochi secondi dopo la scadenza ma prima che il comando giri si onora, perché le box sono ancora nostre.

## 9. Le email non possono far fallire un pagamento
- **Cosa:** partono dopo che lo stato è salvato, dentro un `try/catch` che scrive nel log.
- **Perché:** se il server di posta è giù, il cliente ha comunque pagato e l'ordine è valido; far rispondere 500 al webhook provocherebbe nuovi tentativi per un problema che non c'entra.

## 10. Pagamento simulato, ma dallo stesso codice del vero
- **Cosa:** con `stripe-mock` non ci sono carte né webhook. `POST /orders/{id}/simulate-payment` (attivo solo in sviluppo, 404 con Stripe vero) costruisce un evento e lo passa **al gestore usato dal webhook**.
- **Perché:** così la demo percorre davvero la stessa strada del pagamento reale (cambio di stato, email, rilascio stock) e non una scorciatoia diversa. L'unica parte non provata con carte reali è la raccolta della carta nell'app, che sta nell'SDK di Stripe.
- **Dove:** [OrderController.php](../../apps/api/app/Http/Controllers/OrderController.php), [PaymentEventHandler.php](../../apps/api/app/Services/Orders/PaymentEventHandler.php).

## 11. Prezzo e tariffa sono istantanee; il minimo di Stripe è una regola
- **Cosa:** ogni riga copia prezzo e tariffa della box al momento dell'ordine. Un totale sotto 0,50 € è rifiutato con un messaggio chiaro.
- **Perché:** il guadagno promesso al venditore e il prezzo pagato dal cliente non devono cambiare per una modifica successiva. Il minimo chiude il caso "prezzo zero" lasciato aperto al giorno 6 (nessun minimo di prezzo per RB-04, ma Stripe non incassa meno di 0,50 €).

## 12. Cosa non c'è (ancora)
Schermate dell'app (carrello, checkout, storico: giorno 9), QR di ritiro e stato "ritirato" (giorno 10), pagamento al venditore (giorno 11), recensioni e reclami (giorno 12), elenco degli ordini nell'API.
