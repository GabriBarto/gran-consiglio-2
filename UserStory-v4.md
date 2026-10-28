# User Story – App "Too Good To Go" per piante (versione 4)

Legenda: **US-xx** = identificativo; **CA** = criteri di accettazione; **[DA DECIDERE]** = punto ancora aperto (vedi sezione finale).

---

## 0. REGOLE DI BUSINESS (valide per tutte le storie)

| Codice | Regola |
|---|---|
| RB-01 | Mercato: **solo Italia**, lingua italiana, valuta EUR, fuso orario Europe/Rome. |
| RB-02 | Venditori ammessi: **solo attività con partita IVA** (vivai, fioristi, negozi con reparto piante, altri). |
| RB-03 | Il prodotto è la **box a sorpresa**: il cliente non conosce il contenuto esatto. Il venditore dichiara un **valore minimo di listino** del contenuto. |
| RB-04 | **Prezzo box** = valore di listino dichiarato × (1 − sconto), con sconto compreso tra **60% e 70%** (scelto dal venditore, validato dal sistema). Esempio: listino 20,00 € e sconto 65% → prezzo 7,00 €. Non esistono valori minimi di listino né di prezzo: vale solo la formula. |
| RB-05 | **Tariffa di servizio**: importo fisso per ogni box venduta, oggi **1,50 €**, **configurabile dal super admin** (non scritta nel codice). **Guadagno venditore = prezzo box − tariffa.** Le commissioni di Stripe sono **a carico della piattaforma**. Il venditore può quindi avere un guadagno nullo o negativo su box di prezzo basso: il sistema glielo mostra chiaramente prima della pubblicazione (US-V06) e il guadagno **può restare negativo** nei suoi riepiloghi. L'app non effettua addebiti automatici al venditore: il trasferimento (RB-11) non è mai inferiore a 0 €. |
| RB-06 | Le box si vendono **solo per il giorno stesso**. Lo stock è giornaliero e scade a fine giornata. |
| RB-07 | Lo stock è un **contatore**: ogni acquisto lo riduce di 1; a 0 la box non è più acquistabile. Non esiste un limite di box per cliente: vale solo la disponibilità del negozio. |
| RB-08 | Il **venditore non può annullare** un ordine. Può solo ridurre lo stock non ancora venduto. |
| RB-09 | Se il cliente **non ritira** nella fascia indicata, **non c'è rimborso**. L'avviso è mostrato in modo evidente prima del pagamento e accettato dal cliente. |
| RB-10 | Rimborsi e dispute sono **gestiti dalla piattaforma** (super admin). |
| RB-11 | Pagamenti tramite **Stripe Connect**. La piattaforma incassa. Il **pagamento al venditore è ritardato**: il trasferimento avviene **24 ore dopo la fine della fascia di ritiro** (sia che il cliente abbia ritirato sia che non si sia presentato), così da coprire il termine per i reclami. Se c'è un reclamo aperto sull'ordine, il trasferimento resta sospeso fino alla decisione. |
| RB-12 | Il ritiro si conferma tramite **codice QR** (stile ritiro pacco in punto di ritiro). |
| RB-13 | **Nessuna foto** nelle schede di negozi e box. Unica eccezione ammessa: foto allegata a un reclamo (vedi US-C14). |
| RB-14 | Le notifiche sono **solo via email**. |
| RB-15 | Cancellazione account ed esportazione dati secondo **GDPR**. |
| RB-16 | Si può ordinare **fino a 2 ore prima della fine della fascia di ritiro** del giorno. |
| RB-17 | **Autenticazione a due fattori obbligatoria per tutti i ruoli** (clienti, venditori, super admin). |

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
- CA4: se l'orario limite d'ordine (RB-16) è passato, la box è mostrata come non più ordinabile.

**US-N03 – Invito alla registrazione all'acquisto**
Come utente non registrato che vuole acquistare una box voglio un pulsante che mi porta alla registrazione/login.
- CA1: l'app memorizza la box e il negozio che stavo guardando.

**US-N04 – Ritorno alla box dopo la registrazione**
Come utente non registrato, completata la registrazione (verifica email e attivazione 2FA), voglio tornare alla box che stavo visualizzando per completare l'acquisto.
- CA1: dopo il primo accesso vengo riportato alla scheda della box.
- CA2: se nel frattempo la box è esaurita o l'orario limite d'ordine è passato, vedo un messaggio chiaro.

**US-N05 – Scelta del tipo di utente**
Come utente non registrato voglio scegliere in registrazione se sono **Cliente** o **Venditore**, così da vedere il modulo corretto.

---

## 2. AUTENTICAZIONE (comune a tutti i ruoli)

**US-A01 – Verifica dell'email**
Come utente appena registrato devo verificare l'indirizzo email tramite un link, per poter accedere.
- CA1: il link scade (es. 24 ore) ed è monouso; posso richiedere un nuovo invio.

**US-A02 – Login**
Come utente registrato voglio accedere con email e password, seguiti dal secondo fattore.
- CA1: dopo ripetuti tentativi errati l'account viene temporaneamente bloccato (rate limiting).
- CA2: i messaggi di errore non rivelano se l'email esiste.

**US-A03 – Recupero password**
Come utente voglio reimpostare la password tramite link ricevuto via email.
- CA1: il link è monouso e a scadenza breve; tutte le sessioni attive vengono revocate al cambio password.
- CA2: il recupero della password non disattiva il secondo fattore.

**US-A04 – Cambio password**
Come utente autenticato voglio cambiare la password inserendo quella attuale.

**US-A05 – Sicurezza della password**
Come utente voglio che la password rispetti requisiti minimi (lunghezza minima, controllo su password comuni) e che sia salvata con hash robusto (argon2/bcrypt).

**US-A06 – Sessioni**
Come utente voglio restare connesso in modo sicuro (token di accesso a breve durata e refresh token ruotabile) e poter fare **logout** con un pulsante dedicato, che invalida la sessione.

**US-A07 – Autenticazione a due fattori (obbligatoria per tutti)**
Come utente (cliente, venditore o super admin) devo attivare la 2FA, per proteggere il mio account.
- CA1: l'attivazione è obbligatoria dopo la verifica dell'email e prima del primo accesso completo.
- CA2: il secondo fattore è un **codice monouso inviato via email** all'indirizzo verificato, a scadenza breve (es. 10 minuti) e limitato nei tentativi.
- CA3: ad ogni accesso da un nuovo dispositivo o sessione il codice viene richiesto di nuovo.
- CA4: se perdo l'accesso alla casella email si applica la procedura di recupero dell'account tramite email alternativa o supporto, gestita dal super admin.
- CA5: l'acquisto della box avviene solo da account con 2FA attiva.

**US-A08 – Accesso con provider esterni** *(opzionale, fase 2)*
Come cliente voglio poter accedere con Google/Apple. **Non essenziale per ora**: da valutare più avanti, insieme al rapporto con la 2FA.

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
- CA2: la quantità massima è lo stock residuo; non esiste un limite per cliente.
- CA3: il carrello mostra totale, risparmio minimo e fascia di ritiro.

**US-C05 – Orario limite d'ordine**
Come cliente voglio poter ordinare **fino a 2 ore prima della fine della fascia di ritiro**, per non pagare una box che non posso ritirare.
- CA1: se la fascia di ritiro dura meno di 2 ore, l'ordine si chiude prima dell'inizio del ritiro; il cliente vede l'orario limite d'ordine nella scheda della box.
- CA2: se l'orario limite passa mentre ho il carrello aperto, il pagamento viene bloccato con un messaggio chiaro.

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
Come cliente voglio vedere gli ordini attivi e passati con stato (pagato, ritirato, non ritirato, in reclamo, rimborsato).

**US-C12 – Recensione**
Come cliente voglio lasciare una **recensione** (voto e commento) al negozio **solo dopo aver ritirato**, una per ordine.
- CA1: il commento può essere segnalato per contenuti non idonei.

**US-C13 – Profilo**
Come cliente voglio modificare nome utente e password e vedere i miei dati.

**US-C14 – Reclamo**
Come cliente voglio aprire un reclamo su un ordine per i casi ammessi: (a) negozio chiuso o box non consegnata, (b) valore evidentemente inferiore al minimo dichiarato, (c) piante morte o danneggiate, (d) caratteristiche dichiarate non rispettate (es. tossicità).
- CA1: posso allegare **foto solo al reclamo**.
- CA2: il reclamo può essere aperto entro **24 ore dal ritiro**; per il caso (a), dove il ritiro non avviene, entro **24 ore dalla fine della fascia di ritiro**.
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
Come venditore non registrato voglio inserire nome utente, email (anche PEC accettata, non obbligatoria), password e conferma password, e accettare T&C e privacy.

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
- CA1: il prezzo è calcolato dal sistema e mostrato prima della conferma (listino, sconto, prezzo, tariffa, mio guadagno).
- CA2: sconti fuori dal range 60–70% sono bloccati.
- CA3: non ci sono minimi di listino o di prezzo; il prezzo è dato dalla formula.
- CA4: se il guadagno è nullo o negativo (prezzo ≤ tariffa), la schermata lo evidenzia in modo chiaro e chiede conferma esplicita prima della pubblicazione.
- CA5: la quantità deve essere ≥ 1.

**US-V07 – Box ricorrenti**
Come venditore voglio salvare una box come modello e ripubblicarla ogni giorno (o in giorni scelti) con quantità aggiornabile, per non reinserirla ogni mattina.

**US-V08 – Chiarezza guadagno**
Come venditore voglio vedere per ogni box: prezzo di vendita, tariffa di servizio e guadagno netto (anche se negativo).

**US-V09 – Modificare una box**
Come venditore voglio modificare i criteri di una box.
- CA1: le modifiche non cambiano gli ordini già pagati.
- CA2: lo stock può essere solo **ridotto fino al numero già venduto**, non annullando ordini.
- CA3: prezzo e valore non possono essere modificati dopo la prima vendita di giornata.

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
Come venditore voglio vedere i miei incassi: ordini, tariffe trattenute, importi trasferiti, in attesa e sospesi.
- CA1: il trasferimento avviene **24 ore dopo la fine della fascia di ritiro**, sia per gli ordini ritirati sia per quelli non ritirati.
- CA2: se c'è un reclamo aperto sull'ordine, l'importo è mostrato come "sospeso" fino alla decisione.
- CA3: se il guadagno di un ordine è negativo, viene mostrato come tale nel riepilogo; il trasferimento è pari a 0 € e non è previsto alcun addebito al venditore.

**US-V17 – Recensioni ricevute**
Come venditore voglio vedere le recensioni, la media e poter segnalare quelle non idonee.

**US-V18 – Reclami ricevuti**
Come venditore voglio essere avvisato via email se un cliente apre un reclamo su un mio ordine e vedere l'esito deciso dalla piattaforma.
- CA1: reclami ripetuti possono portare alla sospensione (vedi US-S03).

**US-V19 – Esportazione e cancellazione account (GDPR)**
Come venditore voglio esportare i miei dati e chiudere l'account.
- CA1: la chiusura è bloccata finché ci sono ordini attivi, payout pendenti o dispute aperte.
- CA2: i dati fiscali sono conservati per legge.

**US-V20 – Logout**
Come venditore voglio uscire dall'account tramite pulsante dedicato.

---

## 5. SUPER ADMIN

**US-S01 – Creazione account e primo accesso**
Come super admin ricevo credenziali iniziali create dal team tecnico (non tramite registrazione pubblica) e devo **cambiare la password al primo accesso** e attivare la 2FA (US-A07).

**US-S02 – Verifica delle attività**
Come super admin voglio vedere la coda delle richieste, consultare i documenti, i dati P.IVA e l'esito dei controlli automatici, e **approvare o rifiutare** con un pulsante, aggiungendo una motivazione in caso di rifiuto.
- CA1: l'esito genera un'email al venditore.
- CA2: i documenti sono accessibili solo agli admin (storage privato).

**US-S03 – Sospensione venditori**
Come super admin voglio sospendere/riattivare un venditore che non rispetta i T&C.
- CA1: da sospeso non può pubblicare box né ricevere nuovi ordini.
- CA2: gli ordini già pagati vengono comunque ritirati e il relativo payout segue la regola RB-11.

**US-S04 – Moderazione clienti e recensioni**
Come super admin voglio vedere le recensioni segnalate, rimuoverle e sospendere i clienti problematici.

**US-S05 – Gestione reclami e rimborsi**
Come super admin voglio esaminare i reclami (dettagli ordine, descrizione, foto) e decidere: rimborso totale, parziale o rifiuto, con rimborso eseguito su Stripe e notifica via email ad entrambe le parti.
- CA1: ogni decisione ha una motivazione registrata.
- CA2: posso associare un'ammonizione o sospensione al venditore.
- CA3: se il payout non è ancora partito, il trasferimento viene ridotto o annullato; se è già partito, si procede alla revoca del trasferimento.

**US-S06 – Gestione contestazioni di pagamento**
Come super admin voglio monitorare eventuali chargeback e contestazioni Stripe collegate agli ordini.

**US-S07 – Configurazione tariffa e parametri**
Come super admin voglio modificare la tariffa di servizio (oggi 1,50 €), il range di sconto, l'orario limite d'ordine (oggi 2 ore prima della fine del ritiro), il ritardo del payout (oggi 24 ore) e il termine per i reclami (oggi 24 ore) **senza rilasciare nuovo codice**.
- CA1: le modifiche valgono solo per i nuovi ordini; gli ordini già pagati mantengono i parametri applicati.

**US-S08 – Gestione categorie**
Come super admin voglio gestire tipologie e categorie di box e gli attributi delle piante.

**US-S09 – Cruscotto**
Come super admin voglio vedere numero di venditori, ordini, ritirati/non ritirati, incassi, tariffe maturate, costi di Stripe e reclami aperti.

**US-S10 – Audit log**
Come super admin voglio che ogni azione amministrativa (approvazioni, sospensioni, rimborsi, recuperi account, modifiche di configurazione) sia registrata con autore e data.

**US-S11 – Logout**
Come super admin voglio uscire dall'account tramite pulsante dedicato.

---

## 6. SISTEMA (processi automatici e requisiti trasversali)

**US-X01 – Chiusura giornata e payout**
Il sistema, a fine giornata, chiude le box invendute e segna gli ordini non ritirati come "non ritirati". Programma poi il trasferimento al venditore per **24 ore dopo la fine della fascia di ritiro**, salvo reclami aperti.

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

1. **Costo reale per la piattaforma:** su box di prezzo basso la tariffa di 1,50 € può non coprire le commissioni Stripe (a carico della piattaforma). Da verificare con un calcolo del margine per box.
2. **Accesso con Google/Apple:** non essenziale, rinviato (US-A08).
3. **Fiscalità** (scontrino/ricevuta, fatturazione della tariffa al venditore) e **requisiti fitosanitari** dei venditori: l'app non è professionale, quindi non sono requisiti di questa fase. Da rivalutare con un consulente se l'app diventerà commerciale.

### Punti già risolti in questa versione
Commissioni Stripe a carico della piattaforma (RB-05); nessun limite di box per cliente (RB-07); orario limite d'ordine 2 ore prima della fine del ritiro (RB-16); payout ritardato di 24 ore (RB-11); reclamo entro 24 ore (US-C14); venditore sospeso: gli ordini pagati vengono ritirati (US-S03); prezzo e valore bloccati dopo la prima vendita (US-V09); PEC facoltativa (US-V01); nessun minimo di listino o prezzo (RB-04); 2FA obbligatoria per tutti, tramite codice via email (RB-17, US-A07); guadagno negativo ammesso, senza addebiti al venditore (RB-05).
