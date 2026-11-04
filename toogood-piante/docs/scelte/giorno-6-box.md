# Giorno 6 – Le box: perché queste scelte

Obiettivo del giorno: il venditore pubblica dall'app una box per oggi, vede prezzo e guadagno prima di confermare, e può modificarla o eliminarla. Riferimenti: US-V06, V08, V09, V10, V11 e regole RB-03…RB-08 in `UserStory-v4.md`. Endpoint: [API-box.md](../API-box.md).

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Il prezzo lo calcola il server, in centesimi interi
- **Cosa:** `prezzo = listino × (100 − sconto) / 100` con aritmetica intera e arrotondamento al centesimo (mezzo verso l'alto); il client non può impostare il prezzo.
- **Perché:** sono soldi. I numeri decimali (`0.1 + 0.2`) sbagliano di pochi centesimi in modo imprevedibile; con i centesimi interi il risultato è esatto e uguale ovunque. Un client modificato non può pubblicare una box a un prezzo diverso: un test lo verifica inviando `price_cents` falsi.
- **Dove:** [PriceCalculator.php](../../apps/api/app/Services/Boxes/PriceCalculator.php).

## 2. Due implementazioni, un solo elenco di casi di prova
- **Cosa:** l'app ricalcola la formula per mostrare il riepilogo mentre si scrive; i casi in `contracts/price-cases.json` sono eseguiti sia dai test PHP sia da `npm test` nell'app (con il test runner incluso in Node, nessuna dipendenza in più).
- **Perché:** il piano tecnico lo chiedeva: due codici per la stessa formula possono divergere in silenzio. Con lo stesso file di casi, se uno dei due cambia il test dell'altro fallisce. Il server ricalcola comunque sempre.
- **Alternativa:** chiedere l'anteprima al server a ogni cifra digitata. Corretta ma lenta e inutile senza rete.
- **Dove:** [pricing.js](../../apps/mobile/src/pricing.js), [price-cases.json](../../contracts/price-cases.json).

## 3. Guadagno nullo o negativo: avviso, conferma, nessun addebito
- **Cosa:** se `prezzo ≤ tariffa` l'app evidenzia il riepilogo in rosso e chiede di spuntare "Ho capito"; il server **rifiuta** (409) la creazione senza `confirm_low_earning: true`.
- **Perché:** la regola RB-05/US-V06 CA4 vuole una conferma esplicita. Metterla solo nell'app non basterebbe (un altro client la salterebbe): il controllo vero è nel server, e la risposta riporta il calcolo così il client può spiegarlo.
- **Nessun minimo:** per RB-04 non esistono minimi di listino o prezzo. Resta un caso limite (prezzo 0) segnalato in [API-box.md](../API-box.md), da gestire nel pagamento.

## 4. La tariffa è un'istantanea
- **Cosa:** ogni box salva la tariffa in vigore alla creazione (`fee_cents`). La tariffa e i limiti dello sconto vengono dalla tabella `config_params`, non dal codice.
- **Perché:** se l'admin cambia la tariffa da 1,50 € a 2,00 €, il guadagno promesso a chi ha già pubblicato (e la conferma data) non deve cambiare. Un test cambia la tariffa a metà e controlla le due box.
- **Dove:** [BusinessConfig.php](../../apps/api/app/Services/Boxes/BusinessConfig.php) (legge sempre dal database: pochi valori, nessuna cache da svuotare).

## 5. Modifica ed eliminazione: vincoli decisi dallo stato della vendita
- **Cosa:** sempre modificabili descrizione, tipologia, categoria e attributi; **valore e sconto solo prima della prima vendita**; la **quantità può solo scendere**, mai sotto il già venduto; l'eliminazione è possibile solo senza vendite.
- **Perché:** sono le regole di US-V09/V10 e RB-08: un cliente che ha già pagato non deve vedere cambiare ciò che ha comprato, e il venditore non può "annullare" ordini riducendo lo stock. L'eliminazione è logica (`softDeletes`), così l'archivio resta per ordini e storico futuri.
- **Concorrenza:** la modifica blocca la riga (`lockForUpdate` in transazione) prima di leggere lo stock venduto: quando arriveranno gli acquisti (giorno 8) un pagamento simultaneo non potrà far scendere la quantità sotto il venduto.

## 6. Solo box di oggi, nel fuso Europe/Rome
- **Cosa:** la box si crea per il giorno corrente e si modifica solo quel giorno. "Oggi" usa il `Clock` del progetto (fuso `Europe/Rome`, simulabile).
- **Perché:** RB-06: le box si vendono solo il giorno stesso e lo stock scade a fine giornata. Con il fuso sbagliato, una box creata dopo mezzanotte in Italia ma prima in UTC cadrebbe nel giorno sbagliato.

## 7. Gli attributi sono codici, con una piccola trappola di Eloquent
- **Cosa:** `attributes` è un elenco di codici (`INDOOR`, `TOXIC_PETS`…) controllati contro la tabella; il modello ha due metodi (`plantAttributes`, `setPlantAttributes`).
- **Perché:** la colonna, prevista dallo schema del giorno 1, si chiama `attributes` come la proprietà interna di Eloquent: leggerla direttamente restituirebbe i dati interni del modello. I metodi evitano l'equivoco in un solo punto.
- **Dove:** [BoxOffer.php](../../apps/api/app/Models/BoxOffer.php).

## 8. Cosa non c'è (ancora)
Acquisto e decremento automatico dello stock (giorno 8: oggi lo stock "venduto" si può solo simulare nei test), foto delle box, box ricorrenti (roadmap R2), fasce di pubblicazione per orario.
