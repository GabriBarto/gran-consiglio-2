# Giorno 11 – Pagamento ritardato al venditore e incassi: perché queste scelte

Obiettivo del giorno: il venditore riceve il suo guadagno 24 ore dopo la fine della fascia di ritiro, e vede in app quanto ha incassato, quanto aspetta e quanto è sospeso. Riferimenti: US-V16, US-X01, RB-05, RB-11 in `UserStory-v4.md`. Endpoint: [API-incassi.md](../API-incassi.md).

Ogni scelta: **cosa**, **perché**, **alternativa**.

## 1. Il payout nasce al pagamento, parte più tardi
- **Cosa:** nello stesso istante in cui l'ordine diventa `PAID` si crea una riga `payouts` in stato `SCHEDULED`, con importo e data di esecuzione (`fine fascia + payout_delay_hours`). Un comando ogni 10 minuti esegue quelli scaduti.
- **Perché:** la data e l'importo sono fissati quando il cliente paga e restano visibili al venditore ("verranno trasferiti dopo il 16/06"). Un solo payout per ordine è garantito dal database (`order_id` univoco), anche se il pagamento viene notificato due volte.
- **Alternativa:** calcolare tutto a fine giornata guardando gli ordini. Più semplice a prima vista, ma niente da mostrare fino ad allora e più difficile da fermare per un reclamo.

## 2. Il ritardo è un parametro, non una costante
- **Cosa:** `payout_delay_hours` (24) si legge dalla tabella `config_params`, come tariffa e sconto.
- **Perché:** per la demo basta portarlo a pochi minuti senza toccare il codice (PianoTecnico, 5.3). Un test lo cambia e verifica la data.

## 3. L'importo si calcola per box, e non scende mai sotto zero
- **Cosa:** `Σ quantità × max(0, prezzo − tariffa)`, con i valori copiati nell'ordine. Una box in perdita conta 0 e **non** riduce il guadagno delle altre.
- **Perché:** RB-05 dice che il trasferimento non è mai inferiore a 0 € e che al venditore non si addebita nulla. Se avessimo sommato prima e tagliato poi (`max(0, Σ …)`), una box in perdita avrebbe mangiato il guadagno di un'altra nello stesso ordine: sbagliato. Un test lo verifica (5,50 €, non 5,00 €).
- **Guadagno ≠ trasferimento:** il riepilogo mostra il guadagno vero, anche negativo ("−0,50 €, nessun addebito"), mentre il trasferimento è 0 e il payout risulta `SKIPPED`.

## 4. Un pagamento alla volta, con la riga bloccata, e una chiave per Stripe
- **Cosa:** ogni payout si esegue in una transazione che blocca payout e ordine e ricontrolla lo stato. Il trasferimento a Stripe porta `idempotency_key = payout-<id>`.
- **Perché:** due esecuzioni insieme (il comando pianificato e uno lanciato a mano) non devono pagare due volte. La riga bloccata ferma la seconda; la chiave di idempotenza copre il caso peggiore, cioè Stripe che ha ricevuto il trasferimento ma la risposta si è persa e il comando riprova.
- **Compromesso:** la chiamata a Stripe avviene mentre la riga è bloccata. Con un payout alla volta e poche decine al giorno non è un problema; con migliaia andrebbe riorganizzato.

## 5. Se Stripe non risponde, si riprova; gli altri vanno avanti
- **Cosa:** un errore lascia quel payout `SCHEDULED` e lo scrive nei log; gli altri continuano. Il comando esce con codice 1, così un monitoraggio se ne accorge.
- **Perché:** meglio un ritardo di dieci minuti che un pagamento perso o un blocco di tutti. Provato con un trasferimento che fallisce e poi riesce.

## 6. Reclami e rimborsi: già previsti, attivi dal giorno 12
- **Cosa:** alla scadenza, un ordine `IN_COMPLAINT` mette il payout in `HELD`; un ordine `REFUNDED_FULL` lo salta (`SKIPPED`). Nel riepilogo un ordine in reclamo conta come "sospeso" anche prima che il comando lo tocchi.
- **Perché:** RB-11 e US-V16 CA2. Reclami e rimborsi si costruiscono nel giorno 12: oggi le regole sono già scritte e provate forzando lo stato dell'ordine, così il giorno 12 deve solo cambiare lo stato. Un rimborso *parziale* e la revoca di un trasferimento già inviato (`REVERSED`) restano al giorno 12.

## 7. Anche gli ordini precedenti ricevono il loro payout
- **Cosa:** `payouts:run` crea prima i payout mancanti degli ordini già pagati.
- **Perché:** gli ordini di prova fatti nei giorni 8–10 non hanno la riga. Senza questo passo non sarebbero mai pagati e la demo mostrerebbe incassi vuoti.

## 8. L'app: una scheda "Incassi" nella home del venditore
- **Cosa:** sotto "Ritiri di oggi": già trasferito, in attesa, sospeso, tariffe trattenute, e l'elenco degli ordini con la frase che spiega cosa succede ("5,50 € verranno trasferiti dopo il 16/06/2026"). Compare anche per un negozio sospeso, perché gli ordini già pagati si pagano comunque.
- **Perché:** US-V16 chiede proprio questi quattro numeri. Le frasi e i colori sono funzioni pure provate con `npm test`.

## 9. Cosa non c'è (ancora)
Recensioni, reclami, rimborsi e revoca dei trasferimenti (giorno 12); l'email al venditore quando il pagamento parte; l'esportazione degli incassi. Con `stripe-mock` il trasferimento è simulato: con Stripe vero servono chiavi di test e un conto Connect con la capacità `transfers` attiva (mai provato).
