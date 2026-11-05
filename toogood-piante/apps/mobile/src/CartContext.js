import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './AuthContext';
import { addToCart, EMPTY_CART, isOtherShop, removeItem, setQuantity, startCart, syncWithShop, totals } from './cart';

const CartContext = createContext(null);
export const useCart = () => useContext(CartContext);

// Il carrello vive in memoria: si perde se si chiude l'app, ed è sempre ricontrollato dal server al pagamento.
export function CartProvider({ children }) {
  const { user } = useAuth();
  const [cart, setCart] = useState(EMPTY_CART);
  // Le funzioni sotto devono leggere sempre il carrello più recente, anche se chiamate due volte di fila
  const latest = useRef(cart);

  const update = useCallback((next) => {
    latest.current = next;
    setCart(next);
  }, []);

  // Cambiando utente (accesso o uscita) il carrello si svuota: non deve passare da una persona all'altra
  useEffect(() => { update(EMPTY_CART); }, [user?.id, update]);

  const value = useMemo(() => ({
    cart,
    totals: totals(cart),
    isOtherShop: (shopId) => isOtherShop(latest.current, shopId),
    // Aggiunge box (default 1). Restituisce { added, atLimit }. Con un altro negozio nel carrello va prima chiamato `replaceWith`.
    add(shop, box, quantity = 1) {
      const result = addToCart(latest.current, shop, box, quantity);
      update(result.cart);

      return { added: result.added, atLimit: result.atLimit };
    },
    // Svuota il carrello e ricomincia da questa box (dopo la conferma di cambiare negozio)
    replaceWith(shop, box, quantity = 1) {
      const result = startCart(shop, box, quantity);
      update(result.cart);

      return { added: result.added, atLimit: result.atLimit };
    },
    setQuantity: (boxId, quantity) => update(setQuantity(latest.current, boxId, quantity)),
    remove: (boxId) => update(removeItem(latest.current, boxId)),
    clear: () => update(EMPTY_CART),
    // Riallinea con la scheda aggiornata del negozio; restituisce i testi di ciò che è cambiato
    sync(shopData) {
      const result = syncWithShop(latest.current, shopData);
      if (result.cart !== latest.current) update(result.cart);

      return result.changes;
    },
  }), [cart, update]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}
