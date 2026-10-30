# MVP (2 settimane) e Roadmap futura – TooGood Piante

Riferimenti: [UserStory-v4.md](UserStory-v4.md) (tutte le storie), [PianoTecnico.md](PianoTecnico.md) (architettura).
Principio: **in due settimane si costruisce il percorso completo, in forma semplice**. Tutto il resto passa alla roadmap (sezione 3).

---

## 1. Perimetro dell'MVP

### Il percorso che deve funzionare dall'inizio alla fine
registrazione con 2FA → venditore approvato dall'admin → box pubblicata → cliente cerca per distanza → acquisto (Stripe test) → QR → ritiro confermato → payout ritardato → recensione / reclamo con rimborso.

### Dentro l'MVP

| Area | Storie incluse | Semplificazione per l'MVP |
|---|---|---|
| **Account** | A01–A04, A06, A07, C01, V01, S01, C17/V20/S11 | Registrazione con conferma password, verifica email, login con codice 2FA via email, logout, reset password. Admin creato da seeder. |
| **Onboarding venditore** | V02, V03, V04, S02 | Un'unica fascia di ritiro valida per tutti i giorni di apertura; documento P.IVA caricato; verifica P.IVA simulata (WireMock); Stripe Connect in modalità test; email di esito. |
| **Box** | V06, V08, V09, V10, V11 | Creazione di box per **oggi**; prezzo calcolato dal sistema (sconto 60–70%, tariffa 1,50 €); guadagno mostrato anche se negativo; stock a contatore. |
| **Ricerca** | N01, N02, N03–N05, C02, C03 | Ricerca per distanza (geolocalizzazione), città e nome, **a lista** ordinata per distanza; scheda negozio con box e recensioni. Registrazione dalla scheda con ritorno alla box. |
| **Acquisto** | C04–C08 | Carrello di un solo negozio, stock riservato 10 minuti, avviso "nessun rimborso" accettato, pagamento Stripe test, email di conferma al cliente e al venditore. Ordine fino a 2 ore prima della fine del ritiro. |
| **Ritiro** | C09, C10, V12, V14, V15 | QR monouso nella fascia di ritiro, codice alfanumerico alternativo, scansione con fotocamera, elenco degli ordini del giorno. |
| **Denaro** | V16, X01 | Ordini non ritirati marcati a fine fascia, payout 24 ore dopo la fine (parametro), importo `max(0, prezzo − tariffa)`, riepilogo incassi. |
| **Recensioni** | C12, V17 | Una recensione per ordine ritirato, visibile sul negozio e al venditore. |
| **Reclami** | C14 (solo testo), S05 | I 4 casi ammessi, entro 24 ore; l'admin decide rimborso totale, parziale o rifiuto; il payout si blocca. |
| **Admin web** | S02, S03, S05 | Tre pagine: coda verifiche, reclami e rimborsi, sospensione venditori. |
| **Sistema** | X02, X03, X04 | Stock atomico, email transazionali, permessi per ruolo, rate limiting, orologio simulato. |

### Fuori dall'MVP (passano alla roadmap)
Box ricorrenti, orari per giorno ed eccezioni (ferie), vista su mappa, filtri per categoria e prezzo, foto nei reclami, esportazione e cancellazione dati (GDPR), cruscotto admin, audit log, configurazione dei parametri da interfaccia, gestione categorie da admin, moderazione recensioni, sospensione clienti, profilo modificabile, accesso con Google/Apple, recupero account con email persa, monitoraggio chargeback, test end-to-end automatici (Playwright, Maestro), specifica OpenAPI, CI, accessibilità rifinita, messa online.

> **Attenzione:** l'esportazione e la cancellazione dei dati sono un requisito GDPR (RB-15). Per un prototipo con dati finti si può rinviare; **prima di usare l'app con persone reali va fatta** (Roadmap, fase R1).

Parametri come tariffa, sconto, ore limite d'ordine e ritardo del payout nell'MVP si cambiano nel database o nel file di configurazione, non da interfaccia.

---

## 2. Calendario di 14 giorni

**Avanzamento:** giorni 1, 2 e 3 completati. Prossimo: giorno 4. Stato dettagliato in [toogood-piante/STATO.md](toogood-piante/STATO.md).

Ipotesi: lavoro ogni giorno; tu provi l'app sul telefono a fine giornata. Se un giorno scivola, si taglia dal fondo della lista "se avanza tempo" (vedi sotto), mai dal percorso principale.

| Giorno | Obiettivo | Risultato verificabile |
|---|---|---|
| 1 | Chiudere la Fase 0; tabelle principali (migrazioni) e dati fissi (categorie, tipologie, attributi) | `toogood:check-services` tutto verde, test passano |
| 2 | Autenticazione: registrazione, verifica email, login con 2FA, logout, reset password | Flusso completo con le email in Mailpit |
| 3 | App mobile: struttura Expo, navigazione per ruolo, schermate di accesso | Accedo dall'app come cliente e come venditore |
| 4 | Onboarding venditore: dati attività, documento, geocoding, Stripe test, endpoint admin di verifica | Un venditore passa da "in verifica" ad "approvato" |
| 5 | Pannello admin web (coda verifiche) e schermata di stato del venditore in app | Approvo un venditore dal web e lui lo vede in app |
| 6 | Box: creazione, calcolo prezzo e guadagno, modifica, eliminazione, stock | Il venditore pubblica una box dall'app |
| 7 | Ricerca per distanza/città/nome, scheda negozio, ritorno alla box dopo registrazione | **Traguardo 1:** un cliente trova e vede le box |
| 8 | Carrello, stock riservato, PaymentIntent, webhook, email di conferma | Ordine pagato con Stripe test, email ricevute |
| 9 | App cliente: carrello, checkout, storico ordini | Acquisto completo dall'app |
| 10 | QR di ritiro, scansione del venditore, stati ordine, job di fine fascia | Ritiro confermato con la fotocamera |
| 11 | Payout ritardato, riepilogo incassi, elenco ordini del venditore, email nuovo ordine | Avanzo l'orologio di 24 ore e il payout parte |
| 12 | Recensioni, reclami, rimborsi dall'admin | **Traguardo 2:** percorso completo, compresi i reclami |
| 13 | Rifinitura: sospensione venditori, limiti di accesso, casi limite (pagamento tardivo, doppia scansione, ora legale), dati di prova per la demo | Test di concorrenza e dei casi limite verdi |
| 14 | Cuscinetto: correzione dei difetti, copione della demo, README aggiornato | Demo completa dall'inizio alla fine |

**Se si resta indietro**, si taglia in quest'ordine (dal primo che salta): recensioni (giorno 12), reset password (giorno 2), sospensione venditori (giorno 13), ricerca per nome. Il percorso acquisto → QR → payout non si taglia.

### Rischi per le due settimane
1. **L'app mobile si prova solo tramite te:** servono tue prove ogni giorno; senza, i difetti emergono tardi.
2. **Stripe:** `stripe-mock` non genera webhook; l'onboarding Connect reale richiede le chiavi di test Stripe. Se non le hai, l'MVP resta su pagamenti simulati.
3. **Installazioni lente su Windows** (disco condiviso con Docker): già visto con Composer.
4. **Decisioni in sospeso** rallentano: ogni domanda senza risposta costa mezza giornata.

---

## 3. Roadmap futura

| Fase | Tema | Contenuto |
|---|---|---|
| **R1** | Conformità (prima di usare l'app con persone reali) | Esportazione e cancellazione dati (GDPR), fiscalità (scontrini, fatturazione della tariffa), requisiti fitosanitari dei venditori, T&C e privacy definitivi |
| **R2** | Strumenti per il venditore | Box ricorrenti, orari per giorno ed eccezioni (ferie, festivi), statistiche vendite, modifica profilo e attività |
| **R3** | Esperienza del cliente | Vista su mappa, filtri per categoria e prezzo, ricerca tollerante agli errori, preferiti, accesso con Google/Apple, modifica profilo |
| **R4** | Fiducia e moderazione | Foto nei reclami, moderazione e segnalazione delle recensioni, sospensione clienti, audit log, monitoraggio chargeback, recupero account se si perde l'email |
| **R5** | Qualità e messa online | Test end-to-end (Playwright, Maestro), specifica OpenAPI, CI, accessibilità, messa online, monitoraggio, Stripe Connect con verifica reale dei venditori |
| **R6** | Amministrazione avanzata | Cruscotto, configurazione dei parametri da interfaccia, gestione categorie e attributi, report finanziari |
| **R7** | Crescita | Notifiche push, più sedi per venditore, statistiche di impatto ambientale, programmi fedeltà |

Ogni voce della roadmap rimanda alle storie di [UserStory-v4.md](UserStory-v4.md); le storie non citate nel perimetro MVP restano valide e si pianificano da qui.
