import { useCallback, useEffect, useLayoutEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Switch, Text, View } from 'react-native';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { quantityInCart } from '../cart';
import { useCart } from '../CartContext';
import { errorMessage } from '../errors';
import { getLastPosition, requestPosition } from '../location';
import { savePendingBox } from '../pendingBox';
import { timeOf } from '../orders';
import { formatDistance, formatEuro } from '../pricing';
import { Button, Card, colors, ErrorText, Field, Hint, LinkText, Screen, Stepper, Title } from '../ui';

const ratingText = (rating) => (rating.count > 0
  ? `★ ${String(rating.average).replace('.', ',')} (${rating.count} ${rating.count === 1 ? 'recensione' : 'recensioni'})`
  : 'Nessuna recensione');

// Ricerca dei negozi per distanza, città e nome. Pubblica: ci arriva anche chi non ha un account.
export function ShopsScreen({ navigation }) {
  const { user, logout } = useAuth();
  const cart = useCart();
  const [name, setName] = useState('');
  const [city, setCity] = useState('');
  const [showEmpty, setShowEmpty] = useState(false);
  const [loc, setLoc] = useState({ state: 'asking', position: null });
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  // In alto a destra: "Accedi" per chi non ha l'accesso, "Esci" per chi è dentro
  useLayoutEffect(() => {
    navigation.setOptions({
      title: 'Negozi',
      headerRight: () => (user
        ? <LinkText onPress={logout}>Esci</LinkText>
        : <LinkText onPress={() => navigation.navigate('Login')}>Accedi</LinkText>),
    });
  }, [navigation, user, logout]);

  const locate = useCallback(async () => {
    setLoc((l) => ({ ...l, state: 'asking' }));
    setLoc(await requestPosition());
  }, []);

  // All'avvio l'app chiede il permesso; se negato si cerca solo per città o nome (US-N01 CA1)
  useEffect(() => { locate(); }, [locate]);

  // Ricerca con una piccola attesa mentre si scrive; una risposta vecchia non sovrascrive quella nuova
  useEffect(() => {
    if (loc.state === 'asking') return undefined;
    let active = true;
    const timer = setTimeout(async () => {
      try {
        const data = await api.shops({
          lat: loc.position?.lat, lng: loc.position?.lng, q: name.trim(), city: city.trim(), include_empty: showEmpty ? 1 : undefined,
        });
        if (!active) return;
        setResult(data);
        setError('');
      } catch (e) {
        if (active) setError(errorMessage(e));
      }
    }, 350);
    return () => { active = false; clearTimeout(timer); };
  }, [name, city, showEmpty, loc]);

  return (
    <Screen>
      <Title>Box di piante vicino a te</Title>

      {user?.role === 'CUSTOMER' ? (
        <View style={{ flexDirection: 'row', gap: 10, marginBottom: 12 }}>
          <View style={{ flex: 1 }}>
            <Button title={cart.totals.units > 0 ? `Carrello (${cart.totals.units})` : 'Carrello'} secondary onPress={() => navigation.navigate('Cart')} />
          </View>
          <View style={{ flex: 1 }}>
            <Button title="I miei ordini" secondary onPress={() => navigation.navigate('Orders')} />
          </View>
        </View>
      ) : null}

      {loc.state === 'granted' ? <Text style={{ color: colors.green, marginBottom: 12 }}>I negozi sono ordinati per distanza da te.</Text> : null}
      {loc.state === 'asking' ? <Hint>Cerco la tua posizione…</Hint> : null}
      {loc.state === 'denied' || loc.state === 'unavailable' ? (
        <View style={{ marginBottom: 12 }}>
          <Hint>
            {loc.state === 'denied'
              ? 'Senza il permesso di posizione puoi cercare per città o per nome.'
              : 'Non riesco a trovare la tua posizione: cerca per città o per nome.'}
          </Hint>
          <Button title="Usa la mia posizione" secondary onPress={locate} />
        </View>
      ) : null}

      <Field label="Nome del negozio" value={name} onChangeText={setName} autoCapitalize="words" placeholder="Es. Vivaio Rossi" />
      <Field label="Città" value={city} onChangeText={setCity} autoCapitalize="words" placeholder="Es. Bologna" />
      <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 16 }}>
        <Switch value={showEmpty} onValueChange={setShowEmpty} trackColor={{ true: colors.green }} />
        <Text style={{ flex: 1, marginLeft: 10, color: colors.text }}>Mostra anche i negozi senza box oggi</Text>
      </View>

      <ErrorText>{error}</ErrorText>
      {!result && !error ? <ActivityIndicator color={colors.green} /> : null}
      {result?.data.length === 0 ? (
        <Hint>Nessun negozio trovato. Prova a cambiare città o nome{showEmpty ? '' : ', o mostra anche i negozi senza box oggi'}.</Hint>
      ) : null}

      {result?.data.map((shop) => (
        <Pressable key={shop.id} onPress={() => navigation.navigate('Shop', { shopId: shop.id })}>
          <Card>
            <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{shop.business_name}</Text>
            <Text style={{ color: colors.muted, marginBottom: 6 }}>
              {shop.city}{shop.distance_m != null ? ` · a ${formatDistance(shop.distance_m)}` : ''}
            </Text>
            {shop.available_units > 0 ? (
              <Text style={{ color: colors.green, fontWeight: '600' }}>
                {shop.available_units} {shop.available_units === 1 ? 'box disponibile' : 'box disponibili'} da {formatEuro(shop.min_price_cents)}
              </Text>
            ) : <Text style={{ color: colors.muted }}>Nessuna box disponibile oggi</Text>}
            <Text style={{ color: colors.text, marginTop: 4 }}>Ritiro oggi {shop.pickup_from}–{shop.pickup_to}</Text>
            {shop.available_units > 0 && !shop.orders_open ? <Text style={{ color: colors.error, marginTop: 4 }}>Ordini chiusi per oggi</Text> : null}
            <Text style={{ color: colors.muted, marginTop: 4 }}>{ratingText(shop.rating)}</Text>
          </Card>
        </Pressable>
      ))}
    </Screen>
  );
}

// Scheda di un negozio: orari, fascia di ritiro di oggi, recensioni e box disponibili.
export function ShopScreen({ route, navigation }) {
  const { shopId, boxId, fromIntent } = route.params;
  const { user } = useAuth();
  const cart = useCart();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const position = getLastPosition();
      setData(await api.shop(shopId, position ? { lat: position.lat, lng: position.lng } : {}));
      setError('');
    } catch (e) {
      setError(e.code === 'NOT_FOUND' ? 'Questo negozio non è più disponibile.' : errorMessage(e));
    }
  }, [shopId]);

  useEffect(() => { load(); }, [load]);

  if (!data) {
    return (
      <Screen>
        {error ? <><ErrorText>{error}</ErrorText><Button title="Riprova" onPress={load} /></> : <ActivityIndicator size="large" color={colors.green} />}
      </Screen>
    );
  }

  const { shop, rating, boxes } = data;

  // Acquisto da visitatore: si ricorda la box e si porta all'accesso (US-N03); dopo il primo accesso si torna qui (US-N04)
  const buyAsGuest = async (box) => {
    await savePendingBox({ shopId: shop.id, boxId: box.id });
    navigation.navigate('Login');
  };

  // Se si torna dalla registrazione la box può essere cambiata nel frattempo (US-N04 CA2)
  let notice = null;
  if (fromIntent && boxId) {
    const box = boxes.find((b) => b.id === boxId);
    if (!box) notice = { bad: true, text: 'La box che stavi guardando non è più disponibile.' };
    else if (box.sold_out) notice = { bad: true, text: 'La box che stavi guardando nel frattempo è stata esaurita.' };
    else if (!box.orderable) notice = { bad: true, text: `L’orario limite per ordinare questa box è passato (le ${timeOf(shop.order_deadline)}).` };
    else notice = { bad: false, text: 'Bentornato! Ecco la box che stavi guardando.' };
  }

  return (
    <Screen>
      <Title>{shop.business_name}</Title>
      <Text style={{ color: colors.text }}>{shop.address}</Text>
      <Text style={{ color: colors.muted, marginBottom: 12 }}>
        {shop.city}{shop.distance_m != null ? ` · a ${formatDistance(shop.distance_m)} da te` : ''}
      </Text>

      <Card>
        <Line label="Orari" value={`${shop.opens_at} – ${shop.closes_at}`} />
        <Line label="Ritiro oggi" value={`${shop.pickup_from} – ${shop.pickup_to}`} />
        <Line label="Ordini fino alle" value={shop.orders_open ? timeOf(shop.order_deadline) : `${timeOf(shop.order_deadline)} (chiusi)`} />
        <Line label="Recensioni" value={ratingText(rating)} />
      </Card>

      {notice ? (
        <Card style={{ borderColor: notice.bad ? colors.error : colors.green }}>
          <Text style={{ color: notice.bad ? colors.error : colors.green, fontWeight: '600' }}>{notice.text}</Text>
        </Card>
      ) : null}

      <Text style={{ fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: 8 }}>Box di oggi</Text>
      {boxes.length === 0 ? <Hint>Questo negozio non ha box in vendita oggi.</Hint> : null}
      {boxes.map((box) => (
        <BoxCard key={box.id} box={box} shop={shop} highlighted={box.id === boxId} user={user} onGuestBuy={() => buyAsGuest(box)} />
      ))}

      {user?.role === 'CUSTOMER' && cart.totals.units > 0 ? (
        <Button title={`Vai al carrello · ${cart.totals.units} box · ${formatEuro(cart.totals.total_cents)}`} onPress={() => navigation.navigate('Cart')} />
      ) : null}

      <LinkText onPress={load}>Aggiorna</LinkText>
    </Screen>
  );
}

// Una box della scheda: descrizione, prezzo, risparmio, stock e — per il cliente — quantità e "Aggiungi al carrello".
function BoxCard({ box, shop, highlighted, user, onGuestBuy }) {
  const cart = useCart();
  const [quantity, setQuantity] = useState(1);
  const [message, setMessage] = useState('');
  const isCustomer = user?.role === 'CUSTOMER';
  const inCart = quantityInCart(cart.cart, box.id);
  const left = Math.max(0, box.stock_available - inCart); // quante se ne possono ancora aggiungere

  const report = ({ added, atLimit }) => {
    setMessage(added === 0
      ? 'Hai già nel carrello tutte le box disponibili.'
      : `Aggiunte al carrello: ${added}.${atLimit ? ' Hai preso tutte quelle disponibili.' : ''}`);
    setQuantity(1);
  };

  const addToCart = () => {
    // Un carrello è di un solo negozio (US-C04 CA1): per cambiare negozio si chiede conferma
    if (cart.isOtherShop(shop.id)) {
      Alert.alert(
        'Cambiare negozio?',
        'Il carrello contiene box di un altro negozio, ma un ordine può avere box di un solo negozio. Vuoi svuotarlo e aggiungere questa box?',
        [
          { text: 'Annulla', style: 'cancel' },
          { text: 'Svuota e aggiungi', style: 'destructive', onPress: () => report(cart.replaceWith(shop, box, quantity)) },
        ],
      );
      return;
    }
    report(cart.add(shop, box, quantity));
  };

  return (
    <Card style={highlighted ? { borderColor: colors.green, borderWidth: 2 } : null}>
      <Text style={{ fontSize: 16, fontWeight: '700', color: colors.text }}>{box.box_type.label}</Text>
      <Text style={{ color: colors.muted, marginBottom: 6 }}>{box.category.label}</Text>
      {box.description ? <Text style={{ color: colors.text, marginBottom: 6 }}>{box.description}</Text> : null}
      {box.attributes.length ? <Text style={{ color: colors.muted, marginBottom: 6 }}>{box.attributes.map((a) => a.label).join(' · ')}</Text> : null}

      <Text style={{ fontSize: 22, fontWeight: '700', color: colors.text }}>{formatEuro(box.price_cents)}</Text>
      <Text style={{ color: colors.green, fontWeight: '600' }}>
        Risparmi almeno {formatEuro(box.min_saving_cents)} (valore di listino {formatEuro(box.list_value_cents)})
      </Text>

      {box.sold_out ? (
        <Text style={{ color: colors.error, fontWeight: '700', marginTop: 8 }}>Esaurita</Text>
      ) : (
        <Text style={{ color: colors.text, marginTop: 6 }}>{box.stock_available === 1 ? 'Ne resta 1' : `Ne restano ${box.stock_available}`}</Text>
      )}

      {box.unavailable_reason === 'ORDER_DEADLINE_PASSED' ? (
        <Text style={{ color: colors.error, fontWeight: '600', marginTop: 8 }}>Ordini chiusi: il limite era alle {timeOf(shop.order_deadline)}</Text>
      ) : null}

      {box.orderable && !user ? <Button title="Accedi per acquistare" onPress={onGuestBuy} /> : null}

      {box.orderable && isCustomer ? (
        <View style={{ marginTop: 12 }}>
          {inCart > 0 ? <Text style={{ color: colors.green, fontWeight: '600', marginBottom: 8 }}>Nel carrello: {inCart}</Text> : null}
          {left > 0 ? (
            <>
              <Stepper value={Math.min(quantity, left)} min={1} max={left} onChange={setQuantity} />
              <Button title="Aggiungi al carrello" onPress={addToCart} />
            </>
          ) : <Hint>Hai già nel carrello tutte le box disponibili.</Hint>}
          {message ? <Text style={{ color: colors.muted, marginTop: 8 }}>{message}</Text> : null}
        </View>
      ) : null}
    </Card>
  );
}

const Line = ({ label, value }) => (
  <View style={{ flexDirection: 'row', marginBottom: 4 }}>
    <Text style={{ width: 120, color: colors.muted }}>{label}</Text>
    <Text style={{ flex: 1, color: colors.text }}>{value}</Text>
  </View>
);
