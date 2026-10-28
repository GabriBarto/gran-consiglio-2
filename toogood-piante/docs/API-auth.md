# API di autenticazione

Base: `http://localhost:8080/api`. Tutte le richieste e risposte sono JSON: inviare `Accept: application/json` e `Content-Type: application/json`.
Gli errori hanno la forma `{ "code": "...", "message": "..." }`; gli errori di validazione (422) hanno `{ "message", "errors": { campo: [...] } }`.

## Flusso di accesso (2FA via email)

```
POST /auth/login          { email, password }                  → { challenge_id, expires_in }   + email con codice a 6 cifre
POST /auth/login/verify   { challenge_id, code, device? }      → { access_token, refresh_token, token_type, expires_in, user }
```

L'access token dura **15 minuti**, il refresh token **30 giorni**. Si usa nell'header `Authorization: Bearer <access_token>`.

## Endpoint

| Metodo | Percorso | Autenticato | Descrizione |
|---|---|---|---|
| POST | `/auth/register` | no | `role` (`customer`/`seller`), `username`, `email`, `password`, `password_confirmation`, `accept_terms`. Invia l'email di conferma. |
| GET | `/auth/verify-email/{id}/{hash}` | link firmato | Conferma l'email (apre una pagina HTML). Il link vale 24 ore. |
| POST | `/auth/verify-email/resend` | no | `email`. Risposta sempre 202. |
| POST | `/auth/login` | no | Primo passo dell'accesso. |
| POST | `/auth/login/verify` | no | Secondo passo: codice ricevuto per email. |
| POST | `/auth/refresh` | no | `refresh_token`. Restituisce una nuova coppia di token e **invalida il refresh token usato**. |
| POST | `/auth/logout` | sì | Chiude questo dispositivo. Opzionale: `refresh_token`. Risposta 204. |
| POST | `/auth/password/forgot` | no | `email`. Invia un codice a 6 cifre (valido 30 minuti). Risposta sempre 202. |
| POST | `/auth/password/reset` | no | `email`, `code`, `password`, `password_confirmation`. Chiude tutte le sessioni. |
| POST | `/auth/password/change` | sì | `current_password`, `password`, `password_confirmation`. Chiude le altre sessioni e restituisce un nuovo `refresh_token`. |
| GET | `/me` | sì | Dati dell'utente: `id`, `username`, `email`, `role`, `email_verified`, `must_change_password`. |

## Regole

- **Password:** almeno 10 caratteri, con lettere e numeri.
- **Nome utente:** 3–40 caratteri (lettere, numeri, spazi e `. _ ' & -`), univoco senza distinguere le maiuscole.
- **Codice 2FA:** 6 cifre, valido 10 minuti, monouso, massimo 5 tentativi; un nuovo accesso invalida i codici precedenti.
- **Admin:** creato dal seeder; con `must_change_password = true` le rotte di funzionalità rispondono 403 `PASSWORD_CHANGE_REQUIRED` finché non cambia la password.
- **Account sospesi:** 403 `ACCOUNT_SUSPENDED` al login, 403 `ACCOUNT_NOT_ACTIVE` con un token già emesso.
- **Limiti di richieste (429):** accesso 5/minuto per email e IP; codice 10/minuto; registrazione 10/ora per IP; recupero password 5/ora; reset password 5/minuto.

## Codici di errore

`INVALID_CREDENTIALS`, `EMAIL_NOT_VERIFIED`, `ACCOUNT_SUSPENDED`, `ACCOUNT_NOT_ACTIVE`, `INVALID_CODE`, `CODE_EXPIRED`, `TOO_MANY_ATTEMPTS`, `INVALID_REFRESH_TOKEN`, `REFRESH_TOKEN_REUSED`, `INVALID_RESET_CODE`, `INVALID_CURRENT_PASSWORD`, `PASSWORD_CHANGE_REQUIRED`, `INVALID_LINK`.

## Note per l'app mobile

- Il link di conferma email usa `APP_URL` di `apps/api/.env` (ora `http://localhost:8080`). Da un telefono vero va sostituito con l'indirizzo IP del PC nella rete locale.
- I messaggi di validazione di Laravel (422) sono in inglese: l'app deve mostrare propri testi in italiano, scelti in base al campo.
- Dopo un `refresh`, il vecchio refresh token va scartato: riusarlo chiude tutte le sessioni.
