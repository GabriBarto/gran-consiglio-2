# API di ricerca e scheda dei negozi (giorno 7)

Base: `http://localhost:8080/api`. Le due rotte sono **pubbliche**: non serve l'accesso, ci arriva anche chi non ha ancora un account (US-N01, N02). Limite: 60 richieste al minuto per indirizzo IP (429 oltre).

Compaiono **solo i negozi che possono vendere**: approvati dall'admin e con il conto di pagamento collegato. Venditori in verifica, rifiutati, sospesi o senza conto non si vedono mai (la scheda risponde 404). Partita IVA, telefono, email, documenti e stato della verifica **non escono mai**.

## Endpoint

| Metodo | Percorso | Descrizione |
|---|---|---|
| GET | `/shops` | Cerca negozi per distanza, città e nome. |
| GET | `/shops/{id}` | Scheda di un negozio con le box di oggi. |

### `GET /shops` — parametri (tutti facoltativi)

| Parametro | Regola |
|---|---|
| `lat`, `lng` | Posizione di chi cerca, in gradi (−90…90 e −180…180). Vanno insieme: uno solo dà 422. Con le coordinate la lista è **ordinata per distanza** e ogni negozio ha `distance_m`. |
| `radius_km` | Solo con le coordinate: esclude i negozi oltre quel raggio (0,1–1000 km). Senza, nessun limite di distanza. |
| `city` | La città **contiene** il testo, senza distinguere maiuscole né accenti (`forli` trova `Forlì`). |
| `q` | Il nome del negozio **contiene** il testo, stesse regole. I caratteri `%` e `_` sono cercati come testo, non come jolly. |
| `include_empty` | `1` mostra anche i negozi **senza box disponibili oggi**; di default solo quelli con almeno una box con stock > 0. |
| `limit` | 1–50, default 30. |

`city` e `q` si sommano (devono valere entrambi). Senza coordinate l'ordine è per nome.

Risposta: `{ date, sorted_by: "distance"|"name", data: [negozio] }`. Ogni negozio:

`id`, `business_name`, `address`, `city`, `latitude`, `longitude`, `distance_m` (intero, `null` senza coordinate), `pickup_from`, `pickup_to` (fascia di ritiro di oggi, `HH:MM`), `available_offers`, `available_units`, `min_price_cents` (`null` se non ci sono box), `rating { average, count }` (`average` è `null` senza recensioni), `orders_open` (vero finché non è passato l'orario limite).

### `GET /shops/{id}` — parametri

`lat` e `lng` (facoltativi, insieme): aggiungono `shop.distance_m`. Risposta:

```
{
  "shop":   { id, business_name, address, city, latitude, longitude, distance_m,
              opens_at, closes_at, pickup_from, pickup_to,
              order_deadline  (ISO con fuso, es. "2026-06-15T17:00:00+02:00"), orders_open },
  "rating": { average, count, reviews: [ { rating, comment, author, created_at } ] },   // fino a 20, le più recenti prima
  "boxes":  [ box ]
}
```

Ogni `box`: `id`, `box_type {code,label}`, `category {code,label}`, `attributes [ {code,label,group} ]`, `description` (può essere `null`), `price_cents`, `list_value_cents`, `min_saving_cents`, `stock_available`, `sold_out`, `orderable`, `unavailable_reason` (`null`, `SOLD_OUT` o `ORDER_DEADLINE_PASSED`).

- Sono le box **di oggi** (giorno di Roma), non eliminate. Quelle **esaurite compaiono**, segnate `sold_out` (US-N02 CA2), **in fondo**; prima quelle ordinabili, dalla più economica.
- `min_saving_cents = listino − prezzo`: l'app scrive "risparmi **almeno** X €", perché il listino è un valore minimo dichiarato dal venditore (CA1).
- `orderable` è falso se la box è esaurita o se è passato l'orario limite: **2 ore prima della fine della fascia di ritiro** (parametro `order_cutoff_hours` in `config_params`). Es. ritiro fino alle 19:00 → si ordina fino alle 17:00 (US-N02 CA4, RB-16).
- Negozio inesistente o non vendibile → 404 `NOT_FOUND`.

## Come si calcola la distanza

`ST_Distance_Sphere(POINT(longitudine, latitudine), POINT(?, ?))` di MySQL: metri in linea d'aria sulla sfera terrestre (non è il percorso a piedi o in auto). Attenzione all'ordine: **longitudine prima**. Il test confronta il risultato con una formula di Haversine indipendente (scarto ammesso 0,5%).

Con `radius_km` si applica prima un riquadro sulle colonne `latitude`/`longitude` (che hanno un indice) e poi il raggio esatto, così con molti negozi non si calcola la distanza per tutti.

## Limiti noti

- Non c'è paginazione: si ricevono al massimo `limit` negozi (50).
- Il nome si cerca per sottostringa: **non** tollera i refusi ("Vivaoi" non trova "Vivaio"). La ricerca tollerante agli errori è nella roadmap (R3), come la vista su mappa e i filtri per categoria e prezzo.
- Le recensioni appaiono quando saranno scritte (giorno 12): oggi media e elenco sono vuoti.
