# Documentazione del database

Il database di TooGood Piante è **MySQL 8.4 in un container Docker**. Queste quattro note lo spiegano da punti di vista diversi. Le informazioni sono state controllate sul database vivo (28 tabelle, 25 chiavi esterne, 1 vincolo `CHECK`), non solo lette dal codice.

| # | Documento | Risponde a |
|---|---|---|
| 1 | [Perché queste tabelle e questi attributi](01-scelte-e-tabelle.md) | Come siamo arrivati allo schema? Perché esiste ogni tabella e ogni colonna? Cosa abbiamo scelto di non fare? |
| 2 | [Schema ER](02-schema-er.md) | Quali sono le entità, come sono collegate, con quali cardinalità? (diagrammi Mermaid) |
| 3 | [Schema logico](03-schema-logico.md) | Quali sono le tabelle, i tipi, le chiavi, i vincoli e gli indici esatti? |
| 4 | [Funzionamento e Docker](04-funzionamento-e-docker.md) | Come gira il database, come ci si collega l'applicazione, come si crea lo schema, come si guarda dentro? |

**Ordine di lettura consigliato:** 2 (per vedere il disegno) → 1 (per capire le scelte) → 3 (per i dettagli) → 4 (per far girare tutto).

## In sintesi

- **28 tabelle**: 17 di business (utenti, venditori, cataloghi, offerte, ordini, ritiro, payout, recensioni, reclami, rimborsi, webhook), 5 di accesso (codici 2FA, token, dispositivi ricordati, reset password) e 6 tecniche di Laravel (cache, coda, migrazioni).
- **Soldi in centesimi interi** e **snapshot** di prezzo, tariffa e fine ritiro al momento dell'acquisto.
- **Le regole importanti sono vincoli del database**: un payout, un QR e una recensione per ordine, stock mai oltre il totale, webhook mai elaborato due volte, storico dei soldi non cancellabile.
- **Schema progettato tutto il giorno 1**, poi solo 4 migrazioni additive (una è della libreria di accesso).
- **Docker:** il container `mysql` ha un volume (`mysql-data`) che conserva i dati, e un controllo di salute da cui dipende l'avvio di PHP. L'applicazione lo raggiunge all'indirizzo **`mysql:3306`** dentro la rete Docker; dal PC è su **`localhost:3307`**.
- **Due database:** `toogood` (sviluppo, persistente) e `toogood_test` (test, in memoria), per non rischiare mai i dati di prova.

Altri documenti collegati: [guida all'avvio](../AVVIO.md) (comandi di migrazione e seeder), [scelte del giorno 1](../scelte/giorno-1-infrastruttura.md) (infrastruttura e primo schema), `PianoTecnico.md` nella cartella superiore (modello dati iniziale).

## Per i grafici

Gli schemi sono in **Mermaid**, un linguaggio testuale per i diagrammi: GitHub li disegna da solo. In VS Code serve l'estensione "Markdown Preview Mermaid Support"; altrimenti si può incollare il blocco su https://mermaid.live per vederlo, scaricarlo come immagine (PNG/SVG) o inserirlo in una presentazione.
