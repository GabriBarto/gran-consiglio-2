# User Story – App "Too Good To Go" per piante (versione 3)

Legenda: **US-xx** = identificativo; **CA** = criteri di accettazione; **[DA DECIDERE]** = punto ancora aperto (vedi sezione finale).

---

## 0. REGOLE DI BUSINESS (valide per tutte le storie)

| Codice | Regola |
|---|---|
| RB-01 | Mercato: **solo Italia**, lingua italiana, valuta EUR, fuso orario Europe/Rome. |
| RB-02 | Venditori ammessi: **solo attività con partita IVA** (vivai, fioristi, negozi con reparto piante, altri). |
| RB-03 | Il prodotto è la **box a sorpresa**: il cliente non conosce il contenuto esatto. Il venditore dichiara un **valore minimo di listino** del contenuto. |
| RB-04 | **Prezzo box** = valore di listino dichiarato × (1 − sconto), con sconto compreso tra **60% e 70%** (scelto dal venditore, validato dal sistema). Esempio: listino 20,00 € e sconto 65% → prezzo 7,00 €. |
| RB-05 | **Tariffa di servizio**: importo fisso per ogni box venduta, oggi **1,50 €**, **configurabile dal super admin** (non scritta nel codice). Guadagno venditore = prezzo box − tariffa (− eventuali commissioni di pagamento, se a suo carico **[DA DECIDERE]**). |
| RB-06 | Le box si vendono **solo per il giorno stesso**. Lo stock è giornaliero e scade a fine giornata. |
| RB-07 | Lo stock è un **contatore**: ogni acquisto lo riduce di 1; a 0 la box non è più acquistabile. |
| RB-08 | Il **venditore non può annullare** un ordine. Può solo ridurre lo stock non ancora venduto. |
| RB-09 | Se il cliente **non ritira** nella fascia indicata, **non c'è rimborso**. L'avviso è mostrato in modo evidente prima del pagamento e accettato dal cliente. |
| RB-10 | Rimborsi e dispute sono **gestiti dalla piattaforma** (super admin). |
| RB-11 | Pagamenti tramite **Stripe Connect**. La piattaforma incassa; il trasferimento al venditore avviene dopo la conferma del ritiro, oppure alla fine della fascia di ritiro se il cliente non si è presentato. |
| RB-12 | Il ritiro si conferma tramite **codice QR** (stile ritiro pacco in punto di ritiro). |
| RB-13 | **Nessuna foto** nelle schede di negozi e box. Unica eccezione ammessa: foto allegata a un reclamo (vedi US-C14). |
| RB-14 | Le notifiche sono **solo via email**. |
| RB-15 | Cancellazione account ed esportazione dati secondo **GDPR**. |

---

## 1. UTENTE NON REGISTRATO

**US-N01 – Cercare negozi**
Come utente non registrato voglio cercare i negozi per **distanza (geolocalizzazione), città e nome**, così da trovare chi vende box vicino a me.
- CA1: all'avvio l'app chiede il permesso di geolocalizzazione; se negato, posso cercare solo per città o nome.
- CA2: la lista è ordinabile per distanza e mostra solo negozi con almeno una box disponibile oggi (con filtro per mostrare anche gli altri).
- CA3: è disponibile una vista su mappa.
- CA4: ricerca per nome tollerante a errori di battitura e maiuscole.

**US-N02 – Consultare un negozio**
Come utente non registrato, una volta selezionato un negozio, voglio vedere nome, indirizzo, orari di apertura e chiusura, **fascia oraria di ritiro di oggi**, recensioni (media e elenco) e le box disponibili oggi con **tipologia, categoria, descrizione, prezzo, valore minimo di listino, risparmio minimo e quantità residua**.
- CA1: il risparmio è mostrato come "risparmi almeno X €" (valore di listino − prezzo).
- CA2: se lo stock è 0 la box è mostrata come esaurita.
- CA3: sono visibili gli attributi della box rilevanti per le piante (es. interno/esterno, tossicità per animali, categoria).

**US-N03 – Invito alla registrazione all'acquisto**
Come utente non registrato che vuole acquistare una box voglio un pulsante che mi porta alla registrazione/login.
- CA1: l'app memorizza la box e il negozio che stavo guardando.

**US-N04 – Ritorno alla box dopo la registrazione**
Come utente non registrato, completata la registrazione (e verifica email), voglio tornare alla box che stavo visualizzando per completare l'acquisto.
- CA1: dopo la verifica dell'email e il primo accesso vengo riportato alla scheda della box.
- CA2: se nel frattempo la box è esaurita o la fascia di ritiro è chiusa, vedo un messaggio chiaro.

**US-N05 – Scelta del tipo di utente**
Come utente non registrato voglio scegliere in registrazione se sono **Cliente** o **Venditore**, così da vedere il modulo corretto.

---

## 2. AUTENTICAZIONE (comune a tutti i ruoli)

**US-A01 – Verifica dell'email**
Come utente appena registrato devo verificare l'indirizzo email tramite un link, per poter accedere.
- CA1: il link scade (es. 24 ore) ed è monouso; posso richiedere un nuovo invio.

**US-A02 – Login**
Come utente registrato voglio accedere con email e password.
- CA1: dopo ripetuti tentativi errati l'account viene temporaneamente bloccato (rate limiting).
- CA2: i messaggi di errore non rivelano se l'email esiste.

**US-A03 – Recupero password**
Come utente voglio reimpostare la password tramite link ricevuto via email.
- CA1: il link è monouso e a scadenza breve; tutte le sessioni attive vengono revocate al cambio password.

**US-A04 – Cambio password**
Come utente autenticato voglio cambiare la password inserendo quella attuale.

**US-A05 – Sicurezza della password**
Come utente voglio che la password rispetti requisiti minimi (lunghezza minima, controllo su password comuni) e che sia salvata con hash robusto (argon2/bcrypt).

**US-A06 – Sessioni**
Come utente voglio restare connesso in modo sicuro (token di accesso a breve durata e refresh token ruotabile) e poter fare **logout** con un pulsante dedicato, che invalida la sessione.

**US-A07 – Autenticazione a due fattori**
Come super admin voglio la **2FA obbligatoria**. Per venditori e clienti la 2FA è facoltativa **[DA DECIDERE]**.

**US-A08 – Accesso con provider esterni** *(opzionale, fase 2)*
Come cliente voglio poter accedere con Google/Apple.

---

## 3. CLIENTE

**US-C01 – Registrazione**
Come cliente non registrato voglio inserire nome utente, email personale e password, con **conferma password**, e accettare **Termini e Condizioni e Informativa privacy** (con casella esplicita), per avere un account personale.
- CA1: nome utente ed email sono univoci.
- CA2: la conferma password deve coincidere.
- CA3: senza accettazione T&C/privacy non si completa la registrazione.

**US-C02 – Cercare negozi**
Come cliente registrato voglio cercare negozi per distanza, città, nome (come US-N01), con filtri per categoria di box e fascia di prezzo.

**US-C03 – Consultare un negozio**
Come cliente registrato voglio vedere le informazioni complete del negozio e le sue box (come US-N02).

**US-C04 – Carrello**
Come cliente voglio aggiungere box al carrello del negozio selezionato **senza superare lo stock disponibile**, così da ricontrollare cosa sto per acquistare.
- CA1: un carrello contiene box di **un solo negozio**.
- CA2: la quantità massima è il minimo tra stock residuo e limite per cliente **[DA DECIDERE: limite box per cliente/negozio/giorno]**.
- CA3: il carrello mostra totale, risparmio minimo e fascia di ritiro.

**US-C05 – Orario limite d'ordine**
Come cliente voglio poter ordinare solo finché la fascia di ritiro di oggi non è chiusa **[DA DECIDERE: margine, es. fino a 30 minuti prima della fine]**, per non pagare una box che non posso ritirare.

**US-C06 – Checkout e accettazione condizioni**
Come cliente voglio vedere, prima del pagamento: riepilogo, fascia di ritiro e l'avviso **"se non ritiri la box non è previsto rimborso"** con casella di accettazione obbligatoria.

**US-C07 – Pagamento**
Come cliente voglio pagare con carta/wallet tramite Stripe in modo sicuro (i dati carta non transitano dai nostri server).
- CA1: durante il pagamento lo stock è **riservato temporaneamente** (es. 10 minuti).
- CA2: se il pagamento fallisce o scade, lo stock torna disponibile.
- CA3: se lo stock finisce mentre pago, ricevo un messaggio e non vengo addebitato.

**US-C08 – Email di conferma ordine**
Come cliente voglio ricevere via email, dopo il pagamento, riepilogo ordine, negozio, indirizzo, fascia oraria di ritiro, istruzioni sul **codice QR** e promemoria che non sono previsti rimborsi in caso di mancato ritiro.

**US-C09 – QR di ritiro**
Come cliente voglio avere nell'app il **codice QR** dell'ordine (e un codice alfanumerico alternativo) da mostrare in negozio.
- CA1: il QR è univoco, monouso, firmato e valido solo nel giorno e nella fascia di ritiro.
- CA2: dopo la scansione l'ordine risulta "ritirato" e il QR non è più riutilizzabile.
- CA3: il QR è visibile anche con connessione debole (salvato localmente una volta caricato).

**US-C10 – Ritiro**
Come cliente voglio ritirare le box ordinate in negozio nella fascia indicata.

**US-C11 – Storico ordini**
Come cliente voglio vedere gli ordini attivi e passati con stato (pagato, ritirato, non ritirato, rimborsato).

**US-C12 – Recensione**
Come cliente voglio lasciare una **recensione** (voto e commento) al negozio **solo dopo aver ritirato**, una per ordine.
- CA1: il commento può essere segnalato per contenuti non idonei.

**US-C13 – Profilo**
Come cliente voglio modificare nome utente e password e vedere i miei dati.

**US-C14 – Reclamo**
Come cliente voglio aprire un reclamo su un ordine per i casi ammessi: (a) negozio chiuso o box non consegnata, (b) valore evidentemente inferiore al minimo dichiarato, (c) piante morte o danneggiate, (d) caratteristiche dichiarate non rispettate (es. tossicità).
- CA1: posso allegare **foto solo al reclamo**.
- CA2: il reclamo è aprible entro un termine limite dal ritiro **[DA DECIDERE, es. 24 ore]**.
- CA3: ricevo un'email sull'esito (rimborso totale, parziale o rifiuto).
- CA4: nessun reclamo per mancato ritiro, né per contenuto "non gradito" se rispetta il minimo dichiarato.

**US-C15 – Esportazione dati (GDPR)**
Come cliente voglio scaricare i miei dati personali in formato leggibile (JSON/CSV).

**US-C16 – Cancellazione account (GDPR)**
Come cliente voglio cancellare il mio account.
- CA1: i dati personali sono **anonimizzati**; gli ordini restano in forma anonima per obblighi fiscali.
- CA2: la cancellazione è bloccata se ho ordini attivi non ancora ritirati o in disputa.

**US-C17 – Logout**
Come cliente voglio uscire dall'account tramite pulsante dedicato.

---

## 4. VENDITORE

**US-V01 – Registrazione account**
Come venditore non registrato voglio inserire nome utente, email (anche PEC accettata, non obbligatoria **[DA DECIDERE]**), password e conferma password, e accettare T&C e privacy.

**US-V02 – Dati dell'attività**
Come venditore in registrazione voglio inserire ragione sociale, **partita IVA**, recapito telefonico, indirizzo dell'attività (geocodificato e validato), orari di apertura e chiusura, **fascia oraria di ritiro** e allegare la **copia della documentazione dell'attività**.
- CA1: la P.IVA è validata nel formato e verificata (VIES/Agenzia Entrate).
- CA2: l'indirizzo viene trasformato in coordinate per la ricerca per distanza.
- CA3: formati file consentiti: PDF/JPG/PNG, dimensione massima definita.
- CA4: l'account è in stato "in verifica" e non può pubblicare.

**US-V03 – Collegamento Stripe**
Come venditore voglio collegare il mio conto di pagamento Stripe (onboarding Connect) per ricevere i pagamenti. Senza onboarding completato non posso pubblicare box.

**US-V04 – Stato della verifica**
Come venditore voglio vedere lo stato della verifica (in corso, approvata, rifiutata con motivazione) e ricevere un'**email** quando cambia, così da sapere se posso pubblicare.
- CA1: in caso di rifiuto posso correggere e ricaricare i documenti.

**US-V05 – Orari e chiusure**
Come venditore voglio gestire gli orari di ritiro per giorno, i giorni di chiusura e le eccezioni (ferie, festivi).

**US-V06 – Creare una box**
Come venditore (approvato) voglio creare una box indicando: **tipologia, categoria, attributi della pianta** (interno/esterno, tossicità per animali/bambini, ecc.), **descrizione generale del contenuto**, **valore di listino** del contenuto, **sconto** (60–70%) e **quantità** per oggi.
- CA1: il prezzo è calcolato dal sistema e mostrato prima della conferma (listino, sconto, prezzo, tariffa 1,50 €, mio guadagno).
- CA2: sconti fuori dal range 60–70% sono bloccati.
- CA3: valori minimi di listino/prezzo **[DA DECIDERE]**.
- CA4: la quantità deve essere ≥ 1.

**US-V07 – Box ricorrenti**
Come venditore voglio salvare una box come modello e ripubblicarla ogni giorno (o in giorni scelti) con quantità aggiornabile, per non reinserirla ogni mattina.

**US-V08 – Chiarezza guadagno**
Come venditore voglio vedere per ogni box: prezzo di vendita, tariffa di servizio e guadagno netto.

**US-V09 – Modificare una box**
Come venditore voglio modificare i criteri di una box.
- CA1: le modifiche non cambiano gli ordini già pagati.
- CA2: lo stock può essere solo **ridotto fino al numero già venduto**, non annullando ordini.
- CA3: prezzo e valore non possono essere modificati dopo la prima vendita di giornata **[DA DECIDERE]**.

**US-V10 – Eliminare una box**
Come venditore voglio eliminare una box che non voglio più vendere.
- CA1: se ci sono ordini attivi per quella box, l'eliminazione non è consentita; posso solo portare lo stock a quanto già venduto.

**US-V11 – Stock automatico**
Come venditore voglio che il contatore delle box si aggiorni automaticamente a ogni acquisto (es. 5 → 4), così da non vendere più di quanto ho.

**US-V12 – Notifica nuovo ordine**
Come venditore voglio ricevere un'**email** per ogni nuovo ordine, con numero ordine, quantità e fascia di ritiro, per prepararlo.
- CA1: nell'app vedo comunque l'elenco aggiornato degli ordini del giorno.

**US-V13 – Email al cliente**
Come venditore voglio che la conferma d'ordine al cliente sia inviata automaticamente dal sistema con le informazioni di ritiro (vedi US-C08).

**US-V14 – Elenco ordini del giorno**
Come venditore voglio vedere gli ordini di oggi con stato (da ritirare, ritirato, non ritirato).

**US-V15 – Scansione QR**
Come venditore voglio **scansionare il QR** del cliente con la fotocamera dell'app per confermare il ritiro, con inserimento manuale del codice come alternativa.
- CA1: l'app mostra se il QR è valido, già usato, scaduto o di un altro negozio.
- CA2: la conferma registra data e ora del ritiro.

**US-V16 – Pagamenti e payout**
Come venditore voglio vedere i miei incassi: ordini, tariffe trattenute, importi trasferiti e in attesa.
- CA1: il trasferimento avviene dopo la conferma del ritiro o, se il cliente non si presenta, alla chiusura della fascia di ritiro.

**US-V17 – Recensioni ricevute**
Come venditore voglio vedere le recensioni, la media e poter segnalare quelle non idonee.

**US-V18 – Reclami ricevuti**
Come venditore voglio essere avvisato via email se un cliente apre un reclamo su un mio ordine e vedere l'esito deciso dalla piattaforma.
- CA1: reclami ripetuti possono portare alla sospensione (vedi US-S05).

**US-V19 – Esportazione e cancellazione account (GDPR)**
Come venditore voglio esportare i miei dati e chiudere l'account.
- CA1: la chiusura è bloccata finché ci sono ordini attivi, payout pendenti o dispute aperte.
- CA2: i dati fiscali sono conservati per legge.

**US-V20 – Logout**
Come venditore voglio uscire dall'account tramite pulsante dedicato.

---

## 5. SUPER ADMIN

**US-S01 – Creazione account e primo accesso**
Come super admin ricevo credenziali iniziali create dal team tecnico (non tramite registrazione pubblica) e devo **cambiare la password al primo accesso** e attivare la 2FA.

**US-S02 – Verifica delle attività**
Come super admin voglio vedere la coda delle richieste, consultare i documenti, i dati P.IVA e l'esito dei controlli automatici, e **approvare o rifiutare** con un pulsante, aggiungendo una motivazione in caso di rifiuto.
- CA1: l'esito genera un'email al venditore.
- CA2: i documenti sono accessibili solo agli admin (storage privato).

**US-S03 – Sospensione venditori**
Come super admin voglio sospendere/riattivare un venditore che non rispetta i T&C.
- CA1: da sospeso non può pubblicare box né ricevere nuovi ordini.
- CA2: gli ordini già pagati vengono gestiti **[DA DECIDERE: rimborso automatico o completamento ritiro]**.

**US-S04 – Moderazione clienti e recensioni**
Come super admin voglio vedere le recensioni segnalate, rimuoverle e sospendere i clienti problematici.

**US-S05 – Gestione reclami e rimborsi**
Come super admin voglio esaminare i reclami (dettagli ordine, descrizione, foto) e decidere: rimborso totale, parziale o rifiuto, con rimborso eseguito su Stripe e notifica via email ad entrambe le parti.
- CA1: ogni decisione ha una motivazione registrata.
- CA2: posso associare un'ammonizione o sospensione al venditore.

**US-S06 – Gestione contestazioni di pagamento**
Come super admin voglio monitorare eventuali chargeback e contestazioni Stripe collegate agli ordini.

**US-S07 – Configurazione tariffa e parametri**
Come super admin voglio modificare la tariffa di servizio (oggi 1,50 €), il range di sconto, i valori minimi e il margine d'ordine **senza rilasciare nuovo codice**.
- CA1: le modifiche valgono solo per i nuovi ordini; gli ordini già pagati mantengono la tariffa applicata.

**US-S08 – Gestione categorie**
Come super admin voglio gestire tipologie e categorie di box e gli attributi delle piante.

**US-S09 – Cruscotto**
Come super admin voglio vedere numero di venditori, ordini, ritirati/non ritirati, incassi, tariffe maturate e reclami aperti.

**US-S10 – Audit log**
Come super admin voglio che ogni azione amministrativa (approvazioni, sospensioni, rimborsi, modifiche di configurazione) sia registrata con autore e data.

**US-S11 – Logout**
Come super admin voglio uscire dall'account tramite pulsante dedicato.

---

## 6. SISTEMA (processi automatici e requisiti trasversali)

**US-X01 – Chiusura giornata**
Il sistema, a fine giornata, chiude le box invendute, segna gli ordini non ritirati come "non ritirati" e avvia i trasferimenti ai venditori.

**US-X02 – Concorrenza sullo stock**
Il sistema impedisce la vendita oltre lo stock anche con acquisti simultanei (decremento atomico).

**US-X03 – Email transazionali**
Il sistema invia le email: verifica account, reset password, conferma ordine (cliente), nuovo ordine (venditore), esito verifica attività, esito reclamo, avvisi di sospensione. Le email sono in italiano, con mittente autenticato (SPF/DKIM/DMARC).

**US-X04 – Sicurezza**
Comunicazioni solo HTTPS, protezione da attacchi comuni (OWASP), controllo dei permessi per ruolo su ogni API, rate limiting, log degli accessi.

**US-X05 – Privacy e conformità**
Informativa e consensi tracciati, minimizzazione dei dati, conservazione limitata, procedure per esportazione e cancellazione, registro dei trattamenti. Verifica con consulente legale su: Codice del Consumo (informazioni precontrattuali e recesso per beni deperibili), obblighi fiscali di marketplace, DSA/P2B, normativa fitosanitaria (passaporto delle piante, specie vietate o protette).

**US-X06 – Accessibilità e prestazioni**
L'app rispetta le linee guida di accessibilità (contrasti, lettori di schermo) e risponde in tempi adeguati anche su rete mobile.

---

## 7. PUNTI ANCORA DA DECIDERE

1. Chi sostiene le commissioni di Stripe (piattaforma o venditore) e come incidono sulla tariffa di 1,50 €.
2. Valore di listino minimo e prezzo minimo di una box.
3. Orario limite d'ordine rispetto alla chiusura della fascia di ritiro.
4. Limite di box per cliente / negozio / giorno.
5. Termine massimo per aprire un reclamo.
6. Sorte degli ordini pagati quando un venditore viene sospeso.
7. Blocco di prezzo e valore dopo la prima vendita della giornata.
8. 2FA facoltativa per clienti e venditori; accesso con Google/Apple.
9. PEC obbligatoria o facoltativa per i venditori.
10. Fiscalità: chi emette scontrino/ricevuta, fatturazione della tariffa al venditore (IVA), da validare con un commercialista.
11. Requisiti fitosanitari da richiedere ai venditori (iscrizione al registro operatori professionali, passaporto delle piante) da validare con un consulente.
