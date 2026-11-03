# Giorno 2 – Autenticazione dell'API: perché queste scelte

Obiettivo del giorno: un accesso sicuro per tre tipi di utente (cliente, venditore, admin) che possa reggere una discussione tecnica. Riferimento per gli endpoint: [API-auth.md](../API-auth.md).

Ogni scelta: **cosa**, **perché**, **alternativa**, **dove**.

---

## 1. Accesso in due passi: password + codice via email

- **Cosa:** `POST /auth/login` (email e password) invia un codice a 6 cifre; `POST /auth/login/verify` lo scambia con i token.
- **Perché:** una password rubata da sola non basta per entrare. Il prodotto gestisce pagamenti e rimborsi, quindi vale la pena avere un secondo fattore.
- **Alternativa scartata:** chiedere il codice solo da un "nuovo dispositivo", come nel piano iniziale. Richiede di riconoscere i dispositivi in modo affidabile (complicato e facile da aggirare). Chiederlo **a ogni accesso** è più semplice e più sicuro; il costo per l'utente è basso perché la sessione dura 30 giorni grazie al refresh token.
- **Dove:** [LoginController.php](../../apps/api/app/Http/Controllers/Auth/LoginController.php), [TwoFactorService.php](../../apps/api/app/Services/Auth/TwoFactorService.php).

### Come è protetto il codice

| Protezione | Perché |
|---|---|
| Valido 10 minuti | Un codice rubato dopo poco non serve più. |
| Massimo 5 tentativi, contati con un `UPDATE ... WHERE attempts < 5` atomico | 6 cifre sono 1.000.000 di combinazioni: senza limite si indovinano. Il conteggio atomico regge anche richieste simultanee. |
| Monouso (`used_at` impostato con aggiornamento condizionato) | Anche due richieste identiche in parallelo non possono consumare lo stesso codice due volte. |
| Un nuovo accesso invalida i codici precedenti | Resta valido un solo codice alla volta. |
| Nel database c'è solo l'**hash** (HMAC-SHA256 con la chiave dell'app) | Chi legge il database non vede i codici. Un hash semplice sarebbe invertibile per forza bruta (solo 1.000.000 di valori); l'HMAC con segreto no. |
| `hash_equals` per il confronto | Confronto a tempo costante. |
| Il `challenge_id` è un UUID, non un numero progressivo | Non si può indovinare né enumerare. |
| L'email del codice parte **subito**, non dalla coda | Se passasse dalla coda, il codice resterebbe scritto in chiaro nella tabella dei job. |

## 2. Access token breve + refresh token con rotazione

- **Cosa:** access token Sanctum di **15 minuti** + refresh token di **30 giorni**, **monouso**: a ogni rinnovo se ne emette uno nuovo e il vecchio viene invalidato.
- **Perché:** se un access token viene rubato vale al massimo 15 minuti. Il refresh token, più potente, vale una volta sola.
- **Rilevamento del riuso:** se un refresh token già sostituito torna in uso, vuol dire che qualcuno lo ha copiato. Il sistema **chiude tutte le sessioni** dell'utente (`REFRESH_TOKEN_REUSED`). Un token revocato per logout o cambio password, invece, viene solo rifiutato: non si buttano fuori gli altri dispositivi per un normale logout.
- **Nel database c'è solo l'hash SHA-256 del refresh token** (colonna `token_hash`, nascosta nelle serializzazioni). Il token è casuale a 64 caratteri, quindi un hash veloce basta (a differenza delle password).
- **Rinnovo atomico:** `UPDATE ... WHERE revoked_at IS NULL`; se non ha toccato nessuna riga, due richieste hanno gareggiato e si tratta come riuso.
- **Alternativa scartata:** un solo token a lunga durata (se rubato, accesso per settimane) o sessioni con cookie (l'app mobile non è un browser).
- **Dove:** [TokenIssuer.php](../../apps/api/app/Services/Auth/TokenIssuer.php), [TokenController.php](../../apps/api/app/Http/Controllers/Auth/TokenController.php), test in `TokenTest.php`.

## 3. Verifica dell'email con link firmato

- **Cosa:** alla registrazione parte un link firmato (`temporarySignedRoute`) valido 24 ore; senza conferma il login risponde `EMAIL_NOT_VERIFIED`.
- **Perché:** assicura che l'indirizzo sia davvero dell'utente (serve per i codici 2FA e per il recupero password). La firma impedisce di fabbricare link a mano, e il `hash` dell'email nel link impedisce che un link vecchio confermi un indirizzo cambiato.
- **Il reinvio** risponde sempre 202 con lo stesso testo, esista o no l'indirizzo.
- **Dove:** [EmailVerificationService.php](../../apps/api/app/Services/Auth/EmailVerificationService.php), [EmailVerificationController.php](../../apps/api/app/Http/Controllers/Auth/EmailVerificationController.php).

## 4. Nessuna informazione utile a chi attacca

- **Credenziali sbagliate:** stessa risposta `INVALID_CREDENTIALS` sia per email inesistente sia per password errata. Se l'utente non esiste si calcola comunque un hash falso, così il **tempo di risposta** non rivela se l'indirizzo è registrato.
- **Recupero password e reinvio conferma:** risposta 202 sempre uguale.
- **Dove:** `LoginController::login`, `PasswordController`.

## 5. Limiti di richieste (429)

Definiti in un solo punto: [AppServiceProvider.php](../../apps/api/app/Providers/AppServiceProvider.php).

| Rotta | Limite | Chiave | Perché |
|---|---|---|---|
| Login | 5/minuto | email + IP | Rallenta chi prova password a raffica su un account. |
| Codice 2FA | 10/minuto | challenge + IP | Si somma al limite di 5 tentativi del codice. |
| Registrazione | 10/ora | IP | Frena la creazione massiva di account. |
| Recupero / reset password | 5/ora, 5/minuto | email + IP | Evita di inondare di email un utente e di indovinare il codice di reset. |
| Cambio password | 5/minuto | utente | Protegge da chi ha un token rubato. |
| Refresh | 30/minuto | IP | Margine largo per l'uso normale. |

## 6. Regole sulle password e sugli account

- **Password:** almeno 10 caratteri con lettere e numeri (`Password::defaults`, un solo punto da cambiare).
- **Hash delle password:** bcrypt con 12 round (`BCRYPT_ROUNDS=12`).
- **`role` e `status` non sono assegnabili in massa**: si impostano nel controller dopo la validazione, mai dall'input. Evita che qualcuno si registri inviando `role=admin`. La registrazione accetta solo `customer` e `seller` (`Rule::in`).
- **Email in minuscolo e ripulita** prima di validare e cercare: `Mario@x.it` e `mario@x.it` sono lo stesso utente.
- **Nome utente** univoco senza distinguere maiuscole, con caratteri ammessi limitati da una regex.
- **Registrazione in transazione** e accettazione dei termini obbligatoria (`accepted`).
- **Admin creato dal seeder**, mai dalla registrazione pubblica, con `must_change_password`: finché non cambia la password le rotte di funzionalità rispondono 403 `PASSWORD_CHANGE_REQUIRED` (middleware `EnsurePasswordIsFresh`). La password iniziale è nota a chi ha il repository, quindi non deve poter fare nulla prima di sostituirla.
- **Account sospesi bloccati** sia al login sia con un token già emesso (middleware `EnsureUserIsActive`): sospendere un utente ha effetto subito, non quando scade il token.
- **Cambio e reset password chiudono le altre sessioni**; dopo il cambio l'app riceve un nuovo refresh token per il dispositivo in uso.

## 7. Errori leggibili dalle app

- **Cosa:** ogni errore ha la forma `{ "code": "INVALID_CODE", "message": "..." }`.
- **Perché:** l'app decide cosa mostrare dal **codice**, non dal testo; il testo può cambiare senza rompere nulla. L'API non fa mai redirect a una pagina di login (`redirectGuestsTo(fn () => null)`): chi non è autenticato riceve 401.
- **Dove:** [ApiError.php](../../apps/api/app/Support/ApiError.php), [bootstrap/app.php](../../apps/api/bootstrap/app.php).

## 8. Come lo abbiamo verificato

- **61 test automatici su 61** (accesso, registrazione, password, token) sul database di test isolato.
- Prova end-to-end sullo stack vivo, leggendo le email vere da Mailpit: registrazione → conferma → login → codice → `/me` → refresh → logout → admin.
- Comando: `docker compose --profile test run --rm php-test php artisan test`.

## Limiti dichiarati

- I messaggi di validazione (422) di Laravel sono in inglese: li traduce l'app mobile.
- La registrazione del venditore crea solo l'account; i dati dell'attività, il documento e Stripe arrivano al giorno 4.
