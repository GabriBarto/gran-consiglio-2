# Roadmap VerdeSalva

Basata su `UserStory.md` (utente non registrato, cliente, venditore, super admin, prezzi delle box).
Le durate sono stime indicative per un gruppo di 5 persone e vanno riviste con il team.

## Punto di partenza (prototipo già su `main`)

| User story | Stato |
|---|---|
| Navigare e cercare negozi/offerte per **città**, testo e distanza | Fatto in Fase 1 |
| Vedere le box con descrizione e prezzo | Fatto |
| Carrello, pagamento simulato, email di conferma, ritiro con codice | Fatto in Fase 1 (web e app mobile) |
| Venditore: creare box, confermare il ritiro | Parziale (account con email e password; manca la registrazione completa del negozio) |
| Account (registrazione, login, logout, ruoli) | Fatto in Fase 0 |
| Recensioni, notifiche, verifica P.IVA, pannello super admin | Da fare |

## Fase 0 – Fondamenta (2 settimane) ✅ completata
Serve a tutto il resto. Fatto: PostgreSQL in Docker, migrazioni, nuovo modello dati (utenti, negozi, licenze, box, ordini, recensioni), registrazione/login/logout/cambio password, ruoli e sospensione, test automatici. Le regole aperte (vedi sotto) restano da decidere prima delle fasi 1 e 2.
- Decidere le regole aperte (vedi "Decisioni da prendere"): ancora aperto.
- Nuovo modello dati: utenti con ruolo (cliente, venditore, admin), negozi, box, ordini, righe d'ordine, recensioni, licenze.
- Passare da SQLite a **PostgreSQL** nel `docker-compose.yml`.
- Autenticazione vera: registrazione, login, logout, password cifrate, sessioni/token.

## Fase 1 – Clienti (3 settimane) ✅ completata
- Registrazione con nome utente, email, password e conferma password.
- Ricerca negozi per **distanza**, città e nome; pagina del negozio (indirizzo, orari di apertura e di ritiro, box).
- Utente non registrato: può navigare; al momento dell'acquisto viene portato alla registrazione e poi **riportato alla box** che stava guardando.
- Scelta del tipo di utente in fase di registrazione.
- Carrello per negozio, con limite alle box disponibili.
- Pagamento (simulato in `app/lib/payment.js`, da sostituire con Stripe) e **email di conferma** con riepilogo e modalità di ritiro.
- Ritiro in negozio nella fascia oraria indicata.

## Fase 2 – Venditori (3 settimane)
- Registrazione con telefono, indirizzo, orari di apertura e di ritiro e **upload della copia della partita IVA**.
- Stato della verifica visibile, con notifica di approvazione o rifiuto; si pubblica solo dopo l'approvazione.
- Creazione box guidata: tipologia, categoria, **allergeni**, prezzo, quantità.
- Regola del prezzo (sconto sul listino) con controllo automatico e confronto "paghi X invece di Y" per il cliente.
- Guadagno = prezzo box − **tariffa fissa** per box venduta.
- Modifica ed eliminazione delle box.
- Notifica a ogni ordine ricevuto; email automatica di conferma al cliente.

## Fase 3 – Super admin (2 settimane)
- Account admin con password da cambiare al primo accesso.
- Revisione delle licenze caricate: approva o rifiuta con un pulsante.
- Moderazione: sospendere venditori e clienti, rimuovere recensioni non idonee.

## Fase 4 – Recensioni e notifiche (2 settimane)
- Il cliente recensisce il negozio dopo il ritiro; il venditore consulta le recensioni.
- Valutazione media visibile nella pagina del negozio.
- Notifiche push e email centralizzate.

## Fase 5 – Qualità e rilascio (2 settimane)
- Test automatici e CI su GitHub (lint, test, build Docker).
- Privacy e GDPR, termini e condizioni, informativa sugli allergeni.
- Deploy in cloud, backup del database, monitoraggio.
- Rifinitura dell'app mobile e prova su dispositivi reali.

## Decisioni da prendere
1. **Regola del prezzo:** le user story dicono "togliere il 50–70% del valore" in un punto e "60% / 70% del listino" in un altro. Va chiarito se è lo sconto o la percentuale di prezzo, e se il range è 50–70 o 60–70.
2. **Tariffa di servizio** per box: importo o percentuale ("da stabilire").
3. **Pagamento:** quale provider e se il pagamento avviene online o in negozio.
4. **Carrello:** un carrello per negozio, come da user story. Si può comprare da più negozi nello stesso ordine?
5. **Distanza:** serve la posizione del telefono e l'indirizzo dei negozi convertito in coordinate (geocoding).
6. **Verifica P.IVA:** solo controllo manuale dell'admin, o anche un controllo automatico.
