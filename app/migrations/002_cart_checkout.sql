-- Fase 1: carrello, pagamento simulato, email di conferma

-- un carrello per utente, limitato a un solo negozio (vincolo applicato dall'API)
CREATE TABLE cart_items (
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  box_id     INTEGER NOT NULL REFERENCES boxes(id) ON DELETE CASCADE,
  qty        INTEGER NOT NULL CHECK (qty > 0),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, box_id)
);

ALTER TABLE orders
  ADD COLUMN paid_at     TIMESTAMPTZ,
  ADD COLUMN payment_ref TEXT;

-- coda delle email: ogni messaggio viene registrato prima dell'invio, così resta consultabile anche senza SMTP
CREATE TABLE emails (
  id         SERIAL PRIMARY KEY,
  to_email   TEXT NOT NULL,
  subject    TEXT NOT NULL,
  body       TEXT NOT NULL,
  order_id   INTEGER REFERENCES orders(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX emails_order_idx ON emails(order_id);
