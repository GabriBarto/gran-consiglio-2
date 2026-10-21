# VerdeSalva – app mobile (Expo / React Native)

Un'unica app con tre sezioni: **Scopri** e **Prenotazioni** per i clienti, **Account** per accedere o registrarsi (cliente o venditore) e gestire il negozio.

- Il cliente prenota e riceve un **QR code** di ritiro.
- Il venditore lo **scansiona con la fotocamera** (o digita il codice) per confermare il ritiro.

## Avvio

1. Avvia il backend dalla radice del repository: `docker compose up -d --build` (porta 8080).
2. Qui dentro:
   ```bash
   cd mobile
   npm install
   npx expo start
   ```
3. Installa **Expo Go** sul telefono e inquadra il QR mostrato nel terminale.
   **Telefono e PC devono essere sulla stessa rete Wi-Fi.**

L'app trova da sola il backend usando l'indirizzo del PC che serve Expo (`http://<IP-del-PC>:8080`).
Se non funziona (es. rete con isolamento tra dispositivi, tunnel), imposta l'indirizzo a mano:

```bash
EXPO_PUBLIC_API_URL=http://192.168.1.20:8080 npx expo start        # macOS/Linux
$env:EXPO_PUBLIC_API_URL="http://192.168.1.20:8080"; npx expo start # PowerShell
```

Su Windows il firewall può bloccare la porta 8080: consenti l'accesso quando Windows lo chiede per Docker.

## Struttura

```
app/                 schermate (Expo Router)
  (tabs)/index.tsx         Scopri: offerte, filtri, statistiche
  (tabs)/prenotazioni.tsx  prenotazioni salvate sul telefono
  (tabs)/venditori.tsx     accesso/registrazione, dashboard venditore, nuova offerta, conferma ritiro
  offerta/[id].tsx         dettaglio e prenotazione
  prenotazione/[code].tsx  QR di ritiro
  scanner.tsx              scansione QR (venditore)
components/ lib/     UI condivisa, client API, formattazione, storage
```

Account demo (password `demo1234`): venditore `glicine@demo.it`, cliente `cliente@demo.it`.
