# Giorno 13 – rifinitura: sospensione venditori, limiti, casi limite e dati demo

Obiettivo del giorno: chiudere i punti lasciati aperti (sospensione dei venditori), coprire con test i casi limite più rischiosi e preparare i dati per la demo. Riferimenti: US-S03, US-S05 CA2, US-V18 CA1 in `UserStory-v4.md`.

## Sospensione dei venditori
- **Cosa:** l'admin sospende un venditore approvato (con motivo) e lo riattiva; il servizio `SellerSuspension` fa tutto con la riga bloccata, così due azioni insieme non si pestano i piedi. La sospensione è lo stato `SUSPENDED` già previsto dal giorno 1: **nessuna migrazione**.
- **Perché non serve altro codice per bloccare le vendite:** `canPublish` (venditore approvato *e* conto collegato) era già il controllo usato da ricerca, creazione box e checkout. Basta cambiare lo stato e tutto si chiude insieme; i test lo verificano su tutte e tre.
- **Ordini già pagati:** si ritirano e il payout segue la regola di sempre (US-S03 CA2). La scansione e `payouts:run` non guardano lo stato del venditore.
- **Il motivo** sta in `verification_note`, lo stesso campo del rifiuto: un campo in meno da gestire, e l'app lo mostra già.
- **Sospensione automatica:** 3 reclami **accolti** (totale o parziale) in 30 giorni. Si conta sugli accolti, non sugli aperti, perché un cliente scorretto non deve poter sospendere un negozio con reclami infondati. Soglia e finestra sono in `config_params` (`0` spegne). Si riattiva solo a mano.
- **Alla decisione del reclamo** l'admin può anche spuntare "sospendi anche il venditore" (US-S05 CA2).
- **La sospensione scatta dopo il salvataggio della decisione**, come le email: se qualcosa va storto non si perde un rimborso già eseguito su Stripe.
- **Parametri nuovi su database già popolati:** `BusinessConfig::intOr` usa un valore di riserva se la riga manca, così un database di sviluppo non ancora riseminato non dà errore dopo un rimborso. Per averli: `php artisan db:seed --class=CatalogSeeder`.

## Limiti di richieste
- Tetto generale di **120 richieste al minuto per utente** su tutte le rotte autenticate, sopra ai limiti più stretti già presenti (accesso, codici, checkout, reclami, scansione, ricerca).

## Casi limite coperti da test (`EdgeCasesTest`)
- **Ora legale** (29 marzo e 25 ottobre 2026): la fascia 17:00–19:00 a Roma cade alle 15–17 UTC d'estate e alle 16–18 d'inverno; il biglietto di ritiro e l'orario limite d'ordine seguono l'ora di Roma, con i bordi inclusi.
- **Fascia di ritiro corta** (meno di 2 ore): l'ordine si chiude prima che il ritiro cominci, come da regola RB-16; il ritiro funziona comunque nei suoi orari.
- Già coperti nei giorni precedenti: pagamento tardivo con rimborso (giorno 8), doppia scansione anche simultanea (giorno 10), reclamo fuori termine (giorno 12).

## Dati per la demo
- `DemoSeeder` (a parte da `db:seed`): 2 clienti, 3 negozi approvati con conto collegato (Bologna, Pesaro, Milano) con le box di oggi, e 1 negozio sospeso. Si può rilanciare ogni mattina: non duplica, aggiunge le box mancanti di oggi. Non gira in produzione. Password unica, stampata dal comando.

## Non fatto
- **Test di concorrenza nuovi:** non ne ho scritti. Quelli veri (8 e 16 acquisti insieme sull'ultima box, 16 scansioni insieme) sono stati fatti a mano sullo stack vivo ai giorni 8 e 10; i test automatici con PHPUnit girano in una sola transazione e non possono simulare richieste parallele vere.
