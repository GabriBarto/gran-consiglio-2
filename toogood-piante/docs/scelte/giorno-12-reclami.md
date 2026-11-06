# Giorno 12 – Recensioni, reclami e rimborsi: perché queste scelte

Obiettivo del giorno: il cliente può recensire dopo il ritiro e contestare un ordine nei casi ammessi; l'admin decide (rimborso totale, parziale o rifiuto) e il denaro segue la decisione. Riferimenti: US-C12, US-C14, US-V17, US-V18, US-S05, RB-10, RB-11 in `UserStory-v4.md`. Endpoint: [API-reclami.md](../API-reclami.md).

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Il termine lo decide il server, con due decorrenze
- **Cosa:** casi b–d: 24 ore dal ritiro (`picked_up_at`). Caso a (negozio chiuso, box non consegnata): da quando il ritiro è aperto fino a fine fascia + 24 ore. Il parametro è `complaint_window_hours`.
- **Perché:** sono le due regole di US-C14 CA2. Il caso a parte dall'inizio della fascia, non dalla fine, perché il cliente che trova la serranda abbassata deve poter segnalarlo subito; prima dell'apertura del ritiro non ha nulla da contestare.
- **Il motivo dipende dall'ordine:** su un ordine ritirato non si può dire "negozio chiuso" e su uno non ritirato non si può dire "piante morte". Il server rifiuta il motivo non coerente (`REASON_NOT_ALLOWED`).
- **App:** l'ordine restituisce `complaint_options` (motivi ammessi adesso e scadenza): l'app non ricalcola nulla.

## 2. Un solo reclamo per ordine, e l'ordine si ferma
- **Cosa:** aprire un reclamo blocca la riga dell'ordine, lo porta a `IN_COMPLAINT` e mette `HELD` il payout. Un secondo reclamo è rifiutato (`COMPLAINT_EXISTS`).
- **Perché:** due richieste insieme non devono creare due reclami (e quindi due rimborsi possibili). Con l'ordine in `IN_COMPLAINT` il comando di fine fascia non lo segna più "non ritirato" e il payout non parte, anche se la scadenza passa.
- **Alternativa:** vincolo di unicità sul database. Il blocco della riga dell'ordine basta ed evita una migrazione.

## 3. Rimborso e payout: il guadagno del venditore segue il rimborso
- **Cosa:** totale → il venditore non incassa nulla; parziale R → il suo payout scende di R, mai sotto zero; rifiuto → il payout riparte intero.
- **Perché:** il cliente è stato rimborsato di una merce che il venditore non ha consegnato bene. Se R supera il guadagno del venditore (es. 6,00 € su un guadagno di 5,50 €) la differenza la assorbe la piattaforma: al venditore non si addebita mai nulla (RB-05).
- **Alternativa:** far pagare al venditore anche l'eccedenza, riprendendola dai payout successivi. Più giusto sulla carta, ma contrario a RB-05 e molto più complesso.

## 4. Stripe dentro la transazione, con chiavi legate al reclamo
- **Cosa:** la decisione blocca reclamo, ordine e payout; chiama Stripe per il rimborso (`complaint-<id>-refund`) e, se il trasferimento era già partito, per lo storno (`complaint-<id>-reversal`); solo se tutto riesce salva. Se Stripe non risponde, l'admin riceve `502` e **nulla cambia**.
- **Perché:** le chiavi di idempotenza fanno sì che, se la risposta di Stripe si perde e l'admin ripete, il rimborso non venga fatto due volte. La riga bloccata ferma due decisioni contemporanee. Come per i payout, la chiamata esterna avviene a riga bloccata: va bene per i volumi di un MVP.
- **Compromesso noto:** se lo storno fallisce (il venditore ha già speso i soldi e il saldo non basta) la decisione è bloccata finché non si risolve, anche se il rimborso al cliente era già possibile. Il ritardo di 24 ore rende il caso raro; un'eventuale gestione "rimborsa comunque e segna il recupero da fare" è rimandata.

## 5. Una recensione è abilitata dal ritiro, non dallo stato dell'ordine
- **Cosa:** si può recensire se l'ordine ha `picked_up_at`, una volta sola (`order_id` è univoco nel database).
- **Perché:** dopo un reclamo lo stato dell'ordine cambia (`REFUNDED_PARTIAL`, `COMPLAINT_REJECTED`…) ma il cliente ha comunque ritirato. La data di ritiro non cambia.
- **Fuori giornata:** segnalare, nascondere o rimuovere recensioni (US-V17 CA, US-S04) è la fase R4 della roadmap. La colonna `status` (`VISIBLE`/`REMOVED`) c'è già e la scheda del negozio mostra solo le visibili.

## 6. Cosa è rimasto fuori
- **Foto nei reclami:** solo testo nell'MVP (come da roadmap); l'upload su S3Mock è la fase R4.
- **Audit log:** chi ha deciso e quando sono nel reclamo (`decided_by`, `decided_at`); una tabella di audit generale arriva con la sospensione dei venditori (giorno 13 / R4).
- **Reclami ripetuti → sospensione del venditore** (US-V18 CA1): giorno 13.
- **Rimborso con Stripe vero:** provato con test e doppi di Stripe; non con `stripe-mock` né con chiavi di test (vedi STATO.md).
