-- Modello dati VerdeSalva (Fase 0)

CREATE TABLE users (
  id                   SERIAL PRIMARY KEY,
  username             TEXT NOT NULL UNIQUE,
  email                TEXT NOT NULL UNIQUE,            -- sempre in minuscolo
  password_hash        TEXT NOT NULL,
  role                 TEXT NOT NULL CHECK (role IN ('cliente', 'venditore', 'admin')),
  status               TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
  must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE sessions (
  token_hash TEXT PRIMARY KEY,                           -- sha256 del token, mai il token in chiaro
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at TIMESTAMPTZ NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX sessions_user_idx ON sessions(user_id);

CREATE TABLE shops (
  id             SERIAL PRIMARY KEY,
  owner_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
  name           TEXT NOT NULL,
  type           TEXT NOT NULL,                          -- vivaio | fiorista | garden
  description    TEXT NOT NULL DEFAULT '',
  emoji          TEXT NOT NULL DEFAULT '🌿',
  phone          TEXT,
  address        TEXT NOT NULL,
  city           TEXT NOT NULL,
  lat            DOUBLE PRECISION,                       -- per la ricerca per distanza (Fase 1)
  lng            DOUBLE PRECISION,
  opening_time   TIME,
  closing_time   TIME,
  pickup_start   TIME,                                   -- fascia oraria abituale di ritiro
  pickup_end     TIME,
  license_status TEXT NOT NULL DEFAULT 'pending' CHECK (license_status IN ('pending', 'approved', 'rejected')),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX shops_owner_idx ON shops(owner_id);

-- copia della partita IVA caricata dal venditore, da approvare o rifiutare (Fasi 2 e 3)
CREATE TABLE licenses (
  id          SERIAL PRIMARY KEY,
  shop_id     INTEGER NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
  file_path   TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  reviewed_by INTEGER REFERENCES users(id),
  reviewed_at TIMESTAMPTZ,
  note        TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE boxes (
  id               SERIAL PRIMARY KEY,
  shop_id          INTEGER NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
  title            TEXT NOT NULL,
  description      TEXT NOT NULL DEFAULT '',
  box_type         TEXT NOT NULL DEFAULT 'sorpresa',
  category         TEXT NOT NULL,
  emoji            TEXT NOT NULL DEFAULT '🪴',
  allergens        TEXT[] NOT NULL DEFAULT '{}',
  list_price_cents INTEGER NOT NULL CHECK (list_price_cents > 0),   -- somma dei prezzi di listino
  price_cents      INTEGER NOT NULL CHECK (price_cents > 0),
  quantity         INTEGER NOT NULL CHECK (quantity >= 0),
  reserved         INTEGER NOT NULL DEFAULT 0 CHECK (reserved >= 0 AND reserved <= quantity),
  pickup_start     TIMESTAMPTZ NOT NULL,
  pickup_end       TIMESTAMPTZ NOT NULL,
  deleted_at       TIMESTAMPTZ,                                     -- eliminazione "morbida": gli ordini passati restano
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (price_cents < list_price_cents)
);
CREATE INDEX boxes_shop_idx ON boxes(shop_id);

CREATE TABLE orders (
  id          SERIAL PRIMARY KEY,
  code        TEXT NOT NULL UNIQUE,
  shop_id     INTEGER NOT NULL REFERENCES shops(id),
  user_id     INTEGER REFERENCES users(id),
  -- temporanei: prenotazione senza account, da rimuovere quando arriva il carrello (Fase 1)
  guest_name  TEXT,
  guest_email TEXT,
  status      TEXT NOT NULL DEFAULT 'reserved' CHECK (status IN ('reserved', 'paid', 'collected', 'cancelled')),
  total_cents INTEGER NOT NULL,
  fee_cents   INTEGER NOT NULL,                                     -- tariffa di servizio trattenuta dalla piattaforma
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (user_id IS NOT NULL OR guest_email IS NOT NULL)
);
CREATE INDEX orders_user_idx ON orders(user_id);
CREATE INDEX orders_shop_idx ON orders(shop_id);

CREATE TABLE order_items (
  id              SERIAL PRIMARY KEY,
  order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  box_id          INTEGER NOT NULL REFERENCES boxes(id),
  qty             INTEGER NOT NULL CHECK (qty > 0),
  unit_price_cents INTEGER NOT NULL,
  unit_fee_cents   INTEGER NOT NULL
);
CREATE INDEX order_items_order_idx ON order_items(order_id);

CREATE TABLE reviews (
  id         SERIAL PRIMARY KEY,
  order_id   INTEGER NOT NULL UNIQUE REFERENCES orders(id),         -- una recensione per ordine ritirato
  user_id    INTEGER NOT NULL REFERENCES users(id),
  shop_id    INTEGER NOT NULL REFERENCES shops(id),
  rating     INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body       TEXT NOT NULL DEFAULT '',
  hidden     BOOLEAN NOT NULL DEFAULT FALSE,                        -- moderazione admin
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX reviews_shop_idx ON reviews(shop_id);
