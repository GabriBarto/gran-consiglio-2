// Carrello del cliente (US-C04): box di un solo negozio, quantità mai oltre lo stock.
// Solo logica, senza schermate né React Native: così si prova con `npm test`.
// Il carrello vive nell'app; il server lo riceve al checkout e lo ricontrolla da capo (stock, prezzo, orario).

export const EMPTY_CART = { shop: null, items: [] };

export const isEmpty = (cart) => cart.items.length === 0;

// Si sta aggiungendo una box di un negozio diverso da quello del carrello?
export const isOtherShop = (cart, shopId) => !isEmpty(cart) && cart.shop.id !== shopId;

export const quantityInCart = (cart, boxId) => cart.items.find((i) => i.box_id === boxId)?.quantity ?? 0;

const shopInfo = (shop) => ({
  id: shop.id,
  business_name: shop.business_name,
  address: shop.address,
  pickup_from: shop.pickup_from,
  pickup_to: shop.pickup_to,
  order_deadline: shop.order_deadline,
});

const itemFrom = (box, quantity) => ({
  box_id: box.id,
  title: box.description || box.box_type.label,
  category: box.category.label,
  price_cents: box.price_cents,
  list_value_cents: box.list_value_cents,
  stock_available: box.stock_available,
  quantity,
});

/**
 * Aggiunge `quantity` box (default 1), senza superare lo stock residuo.
 * Se il carrello contiene un altro negozio, chi chiama deve prima chiedere conferma e usare `startCart`.
 * Restituisce { cart, added, atLimit }: `added` è quante ne sono state messe davvero (0 se si era già al massimo).
 */
export function addToCart(cart, shop, box, quantity = 1) {
  if (isOtherShop(cart, shop.id)) throw new Error('Il carrello contiene box di un altro negozio');

  const base = isEmpty(cart) ? { shop: shopInfo(shop), items: [] } : cart;
  const current = quantityInCart(base, box.id);
  const next = Math.min(current + quantity, box.stock_available);

  if (next <= current) return { cart: base, added: 0, atLimit: true };

  const item = itemFrom(box, next);
  const items = current > 0 ? base.items.map((i) => (i.box_id === box.id ? item : i)) : [...base.items, item];

  return { cart: { shop: base.shop, items }, added: next - current, atLimit: next === box.stock_available };
}

// Svuota il carrello e comincia da questa box (dopo la conferma di cambiare negozio).
export const startCart = (shop, box, quantity = 1) => addToCart(EMPTY_CART, shop, box, quantity);

// Imposta la quantità di una riga: entro lo stock; zero o meno la toglie.
export function setQuantity(cart, boxId, quantity) {
  if (quantity <= 0) return removeItem(cart, boxId);

  const items = cart.items.map((i) => (i.box_id === boxId ? { ...i, quantity: Math.min(Math.floor(quantity), i.stock_available) } : i));

  return { ...cart, items };
}

export function removeItem(cart, boxId) {
  const items = cart.items.filter((i) => i.box_id !== boxId);

  return items.length === 0 ? EMPTY_CART : { ...cart, items };
}

export function totals(cart) {
  return cart.items.reduce((sum, i) => ({
    units: sum.units + i.quantity,
    total_cents: sum.total_cents + i.price_cents * i.quantity,
    // "Risparmi almeno": il listino è un valore minimo dichiarato dal venditore
    min_saving_cents: sum.min_saving_cents + Math.max(0, i.list_value_cents - i.price_cents) * i.quantity,
  }), { units: 0, total_cents: 0, min_saving_cents: 0 });
}

/**
 * Riallinea il carrello con la scheda aggiornata del negozio (GET /shops/{id}): stock, prezzi e orario possono
 * essere cambiati da quando le box sono state aggiunte. Toglie le righe non più acquistabili, riduce le quantità
 * oltre lo stock e dice cosa è cambiato, così nulla cambia di nascosto prima del pagamento.
 * Restituisce { cart, changes: [testi] }.
 */
export function syncWithShop(cart, shopData) {
  if (isEmpty(cart) || cart.shop.id !== shopData.shop.id) return { cart, changes: [] };

  const changes = [];
  const items = [];

  for (const item of cart.items) {
    const box = shopData.boxes.find((b) => b.id === item.box_id);

    if (!box) {
      changes.push(`«${item.title}» non è più in vendita: l'ho tolta dal carrello.`);
    } else if (box.sold_out) {
      changes.push(`«${item.title}» è esaurita: l'ho tolta dal carrello.`);
    } else if (!box.orderable) {
      changes.push(`«${item.title}» non si può più ordinare (orario limite passato): l'ho tolta dal carrello.`);
    } else {
      let quantity = item.quantity;
      if (quantity > box.stock_available) {
        quantity = box.stock_available;
        changes.push(`«${item.title}»: ne restano solo ${box.stock_available}, ho ridotto la quantità.`);
      }
      if (box.price_cents !== item.price_cents) {
        changes.push(`«${item.title}»: il prezzo è cambiato da ${centsToText(item.price_cents)} a ${centsToText(box.price_cents)}.`);
      }
      items.push({ ...itemFrom(box, quantity) });
    }
  }

  if (items.length === 0) return { cart: EMPTY_CART, changes };

  return { cart: { shop: shopInfo(shopData.shop), items }, changes };
}

// Il corpo di POST /orders. `accepted` è la casella "se non ritiri la box non è previsto rimborso".
export const toOrderPayload = (cart, accepted) => ({
  items: cart.items.map((i) => ({ box_id: i.box_id, quantity: i.quantity })),
  accept_no_refund: accepted,
});

const centsToText = (cents) => `${Math.floor(cents / 100)},${String(cents % 100).padStart(2, '0')} €`;
