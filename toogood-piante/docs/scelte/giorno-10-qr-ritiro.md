# Giorno 10 – QR di ritiro: perché queste scelte

Obiettivo del giorno: il cliente mostra un codice, il venditore lo scansiona con la fotocamera e l'ordine risulta ritirato; chi non si presenta entro la fine della fascia risulta "non ritirato". Riferimenti: US-C09, C10, V14, V15, RB-09, RB-12 in `UserStory-v4.md`. Endpoint: [API-ritiro.md](../API-ritiro.md).

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Un QR firmato che dice poco, e un server che controlla tutto
- **Cosa:** il QR contiene soltanto il numero dell'ordine e un identificativo casuale, firmati con HMAC-SHA256 (`TGQR1.<dati>.<firma>`). Alla scansione il server verifica la firma e poi decide guardando il database: negozio, giorno, fascia, già usato.
- **Perché:** la firma serve a scartare subito i QR inventati o modificati. Ma il QR **non è la fonte di verità**: se contenesse l'orario di validità o lo stato dell'ordine, resterebbe "valido" anche dopo un rimborso o una scansione già fatta. Con solo un identificativo, la verità è sempre nel database. E una foto del QR non rivela nulla del cliente: nessun dato personale viaggia nel codice.
- **Chiave dedicata:** la firma usa una chiave derivata da `APP_KEY` e usata solo per i QR, così non coincide con quella dei codici d'accesso.
- **Alternativa:** un JWT (come previsto dal piano). Equivalente come sicurezza, ma richiede una libreria in più per fare esattamente questo: qui bastano venti righe.

## 2. Un codice alternativo per quando la fotocamera non basta
- **Cosa:** accanto al QR, un codice di 8 caratteri (`ABCD-EFGH`) senza `0 O 1 I L`, che il venditore può digitare. Maiuscole, spazi e trattini non contano.
- **Perché:** fotocamera rotta, schermo rovinato, poca luce. Un alfabeto senza caratteri ambigui si può anche dettare al telefono. Con 31 caratteri e 8 posizioni ci sono circa 850 miliardi di combinazioni e le scansioni sono limitate a 30 al minuto per utente: indovinare un codice è impraticabile.

## 3. Una sola scansione riesce, anche con richieste simultanee
- **Cosa:** ordine e biglietto si bloccano durante la scansione e l'uso si registra con un aggiornamento che cambia una riga solo se `used_at` è ancora vuoto. Provato con 16 richieste insieme (8 QR + 8 codici): 1 riuscita, 15 `ALREADY_USED`, nessun errore.
- **Perché:** due venditori (o lo stesso con un doppio tocco) non devono consegnare lo stesso ordine due volte. La stessa tecnica del giorno 8 per lo stock, applicata al biglietto.

## 4. La fascia di ritiro decide, con gli estremi inclusi
- **Cosa:** vale dal primo all'ultimo istante della fascia del giorno dell'ordine; prima risponde "non ancora iniziato" (con l'ora), dopo "fascia finita", il giorno dopo non vale più.
- **Perché:** US-C09 CA1. Un test controlla il secondo prima, il secondo esatto e il secondo dopo, perché è lì che si sbaglia.
- **Un'incoerenza trovata dal test:** il comando che a fine fascia segna "non ritirato" chiudeva l'ordine **già** all'ultimo istante, mentre la scansione lo accettava ancora. Ora il comando chiude solo *dopo* la fine, coerente con la scansione.

## 5. Al venditore gli esiti spiegati, non un generico "errore"
- **Cosa:** QR non valido, codice sconosciuto, ordine di un altro negozio, già usato (con l'ora), non ancora iniziato (con l'ora di inizio), fascia finita, ordine non ritirabile: ognuno ha il suo messaggio (US-V15 CA1).
- **Perché:** davanti a un cliente in coda il venditore deve capire in un secondo cosa è successo. "Già ritirato alle 18:12" evita discussioni.
- **Un negozio non può leggere ordini altrui:** il controllo `WRONG_SHOP` dice solo che l'ordine non è suo, senza rivelare altro.

## 6. Il cliente vede il QR anche senza rete
- **Cosa:** il biglietto, una volta caricato, si salva nell'archivio sicuro del telefono; se la rete non risponde si mostra la copia. Lo stato (non ancora iniziato / in corso / finito / ritirato) si ricalcola sul telefono con gli orari salvati, quindi funziona anche offline.
- **Perché:** US-C09 CA3: nei negozi, nei garage e nei vivai la connessione è spesso scarsa. Ma lo stato "ritirato" visto offline può essere in ritardo: per questo il server resta l'unico che decide.

## 7. La privacy del cliente verso il venditore
- **Cosa:** nell'elenco degli ordini del giorno e nell'esito della scansione il venditore vede solo il **nome utente** e le box; mai email, mai il codice di ritiro (un test controlla che non compaiano).
- **Perché:** per consegnare una box non serve altro. E il codice di ritiro è proprio ciò che dimostra di essere il cliente.

## 8. A fine fascia, "non ritirato" lo decide un comando
- **Cosa:** `orders:close-pickup` ogni minuto porta a `NOT_PICKED_UP` gli ordini pagati la cui fascia è finita, ricontrollando lo stato con la riga bloccata.
- **Perché:** RB-09 (nessun rimborso) e il payout del giorno 11 dipendono dal sapere chi non si è presentato. Un controllo a ogni richiesta sarebbe fragile; un comando pianificato sullo stesso modello della scadenza delle prenotazioni è semplice e si prova con l'orologio simulato.
- **Un effetto collaterale da conoscere:** l'orologio simulato è unico per tutto il database di sviluppo, quindi `clock advance` chiude anche gli ordini *veri di prova*. Durante la prova dal vivo ha chiuso un ordine reale del team: l'ho rimesso com'era e l'ho scritto nella guida.

## 9. Cosa non c'è (ancora)
Recensioni dopo il ritiro (giorno 12), payout al venditore 24 ore dopo la fine della fascia (giorno 11), annullamento di una scansione sbagliata, notifiche push, prova su dispositivo della fotocamera.
