import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Text, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { api } from '../api';
import { toOrderPayload } from '../cart';
import { useCart } from '../CartContext';
import { errorMessage } from '../errors';
import { formatCountdown, formatDate, localTimeOf, paymentOutcomeText, shortageLines, statusInfo, ticketMessage, timeOf } from '../orders';
import { loadTicket, saveTicket } from '../qrStore';
import { formatEuro } from '../pricing';
import { useAction } from '../useAction';
import { Button, Card, colors, ErrorText, Hint, LinkText, Screen, Stepper, Title } from '../ui';

const TONE = { ok: colors.green, warn: '#9a6b00', bad: colors.error, muted: colors.muted };

export function StatusBadge({ status }) {
  const { label, tone } = statusInfo(status);

  return (
    <View style={{ alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 4, borderRadius: 20, borderWidth: 1, borderColor: TONE[tone] }}>
      <Text style={{ color: TONE[tone], fontWeight: '700' }}>{label}</Text>
    </View>
  );
}

// Il biglietto di ritiro (US-C09): QR e codice alternativo da mostrare al negozio nella fascia di ritiro.
// Una volta caricato si salva sul telefono, così si vede anche senza rete.
function PickupTicket({ order }) {
  const [ticket, setTicket] = useState(null);
  const [saved, setSaved] = useState(false); // mostrato dalla copia salvata perché la rete non risponde
  const [failed, setFailed] = useState(false);
  const [now, setNow] = useState(Date.now());

  const load = useCallback(async () => {
    setFailed(false);
    try {
      const fresh = await api.pickupTicket(order.id);
      await saveTicket(order.id, fresh);
      setTicket(fresh);
      setSaved(false);
    } catch {
      const copy = await loadTicket(order.id);
      setTicket(copy);
      setSaved(copy !== null);
      setFailed(copy === null);
    }
  }, [order.id, order.status]);

  useEffect(() => { load(); }, [load]);

  // Lo stato (non ancora iniziato / in corso / finito) si aggiorna da solo mentre la schermata è aperta
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 15000);

    return () => clearInterval(timer);
  }, []);

  if (!ticket) {
    return (
      <Card>
        <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Codice di ritiro</Text>
        {failed ? (
          <>
            <Hint>Non riesco a caricare il codice di ritiro. Controlla la connessione e riprova: una volta caricato resta salvato sul telefono.</Hint>
            <Button title="Riprova" onPress={load} />
          </>
        ) : <ActivityIndicator color={colors.green} />}
      </Card>
    );
  }

  const info = ticketMessage(ticket, now);
  const dimmed = info.state === 'USED' || info.state === 'EXPIRED';

  return (
    <Card>
      <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 10 }}>Codice di ritiro</Text>

      <View style={{ alignItems: 'center', opacity: dimmed ? 0.3 : 1 }}>
        <View style={{ backgroundColor: '#fff', padding: 14, borderRadius: 10, borderWidth: 1, borderColor: colors.border }}>
          <QRCode value={ticket.qr} size={210} ecl="M" />
        </View>
        <Text style={{ fontSize: 26, fontWeight: '700', letterSpacing: 4, color: colors.text, marginTop: 12 }}>{ticket.code}</Text>
        <Text style={{ color: colors.muted, marginTop: 2 }}>se la fotocamera non basta, detta questo codice al negozio</Text>
      </View>

      <Text style={{ color: TONE[info.tone], fontWeight: '600', marginTop: 12 }}>{info.text}</Text>
      {saved ? <Text style={{ color: colors.muted, marginTop: 6 }}>Senza connessione: mostro la copia salvata sul telefono.</Text> : null}
    </Card>
  );
}

const Line = ({ label, value, bold, color }) => (
  <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
    <Text style={{ color: colors.muted }}>{label}</Text>
    <Text style={{ color: color ?? colors.text, fontWeight: bold ? '700' : '400', flexShrink: 1, textAlign: 'right' }}>{value}</Text>
  </View>
);

// ---------------------------------------------------------------------------------------------
// Carrello (US-C04): box di un solo negozio, quantità entro lo stock, totale, risparmio e orari.
// ---------------------------------------------------------------------------------------------
export function CartScreen({ navigation }) {
  const cart = useCart();
  const [changes, setChanges] = useState([]);
  const [error, setError] = useState('');
  const shop = cart.cart.shop;
  const shopId = shop?.id;

  // Ogni volta che si apre il carrello lo si riallinea con il negozio: stock e orario possono essere cambiati
  useFocusEffect(useCallback(() => {
    if (!shopId) return undefined;
    let active = true;
    api.shop(shopId)
      .then((data) => { if (active) { setChanges(cart.sync(data)); setError(''); } })
      .catch((e) => { if (active) setError(e.code === 'NOT_FOUND' ? 'Questo negozio non può più ricevere ordini.' : errorMessage(e)); });

    return () => { active = false; };
  }, [shopId])); // eslint-disable-line react-hooks/exhaustive-deps

  if (!shop) {
    return (
      <Screen>
        <Title>Carrello</Title>
        {changes.map((text) => <Text key={text} style={{ color: colors.error, marginBottom: 8 }}>{text}</Text>)}
        <Hint>Il carrello è vuoto. Cerca un negozio e aggiungi le box che ti interessano.</Hint>
        <Button title="Cerca negozi" onPress={() => navigation.navigate('Shops')} />
      </Screen>
    );
  }

  // Solo un avviso: l'orario lo decide il server al momento dell'ordine
  const closed = Date.now() >= Date.parse(shop.order_deadline);

  const askClear = () => Alert.alert('Svuotare il carrello?', 'Le box torneranno disponibili per tutti.', [
    { text: 'Annulla', style: 'cancel' },
    { text: 'Svuota', style: 'destructive', onPress: () => cart.clear() },
  ]);

  return (
    <Screen>
      <Title>Carrello</Title>

      <Card>
        <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{shop.business_name}</Text>
        <Text style={{ color: colors.muted, marginBottom: 8 }}>{shop.address}</Text>
        <Line label="Ritiro oggi" value={`${shop.pickup_from} – ${shop.pickup_to}`} />
        <Line label="Ordini fino alle" value={timeOf(shop.order_deadline)} color={closed ? colors.error : undefined} />
      </Card>

      {closed ? <Text style={{ color: colors.error, fontWeight: '600', marginBottom: 12 }}>L’orario limite per ordinare da questo negozio è passato.</Text> : null}
      <ErrorText>{error}</ErrorText>
      {changes.map((text) => (
        <Card key={text} style={{ borderColor: '#9a6b00' }}>
          <Text style={{ color: '#9a6b00', fontWeight: '600' }}>{text}</Text>
        </Card>
      ))}

      {cart.cart.items.map((item) => (
        <Card key={item.box_id}>
          <Text style={{ fontSize: 16, fontWeight: '700', color: colors.text }}>{item.title}</Text>
          <Text style={{ color: colors.muted, marginBottom: 8 }}>{item.category} · {formatEuro(item.price_cents)} l’una</Text>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <Stepper value={item.quantity} min={1} max={item.stock_available} onChange={(q) => cart.setQuantity(item.box_id, q)} />
            <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{formatEuro(item.price_cents * item.quantity)}</Text>
          </View>
          {item.quantity >= item.stock_available ? <Text style={{ color: colors.muted, marginTop: 6 }}>Hai preso tutte le box disponibili.</Text> : null}
          <LinkText onPress={() => cart.remove(item.box_id)}>Togli dal carrello</LinkText>
        </Card>
      ))}

      <Card>
        <Line label={`Totale (${cart.totals.units} box)`} value={formatEuro(cart.totals.total_cents)} bold />
        <Line label="Risparmi almeno" value={formatEuro(cart.totals.min_saving_cents)} color={colors.green} />
      </Card>

      <Button title="Procedi al pagamento" onPress={() => navigation.navigate('Checkout')} disabled={closed} />
      <LinkText onPress={() => navigation.goBack()}>Continua gli acquisti</LinkText>
      <LinkText onPress={askClear}>Svuota il carrello</LinkText>
    </Screen>
  );
}

// ---------------------------------------------------------------------------------------------
// Checkout e pagamento (US-C05…C07): riepilogo, avviso "nessun rimborso" da accettare, poi pagamento
// entro i 10 minuti in cui le box restano riservate.
// ---------------------------------------------------------------------------------------------
export function CheckoutScreen({ navigation }) {
  const { cart, totals, clear } = useCart();
  const [accepted, setAccepted] = useState(false);
  const [placed, setPlaced] = useState(null);    // la risposta di POST /orders: ordine e dati del pagamento
  const [problem, setProblem] = useState(null);  // l'errore OUT_OF_STOCK, con le box che mancano
  const [outcome, setOutcome] = useState('');    // perché un pagamento non è andato a buon fine
  const [now, setNow] = useState(Date.now());
  const place = useAction();
  const pay = useAction();

  // Conto alla rovescia dei 10 minuti di prenotazione
  useEffect(() => {
    if (!placed) return undefined;
    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [placed]);

  const submit = async () => {
    setProblem(null);
    const err = await place.run(async () => { setPlaced(await api.createOrder(toOrderPayload(cart, accepted))); });
    if (err?.code === 'OUT_OF_STOCK') setProblem(err);
  };

  const payNow = (kind) => pay.run(async () => {
    const order = await api.simulatePayment(placed.id, kind);

    if (order.status === 'PAID') {
      clear();
      // Dopo il pagamento non si torna indietro al carrello: si riparte dai negozi, con l'ordine sopra
      navigation.reset({ index: 1, routes: [{ name: 'Shops' }, { name: 'Order', params: { orderId: order.id, justPaid: true } }] });
    } else {
      setOutcome(paymentOutcomeText(order.status));
    }
  });

  // ----- Pagamento: l'ordine esiste e le box sono riservate -----
  if (placed) {
    const remaining = Date.parse(placed.reserved_until) - now;
    const expired = remaining <= 0;
    const canPay = !expired && !outcome;

    return (
      <Screen>
        <Title>Pagamento</Title>
        <Card>
          <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>Ordine {placed.number}</Text>
          <Text style={{ color: colors.muted, marginBottom: 8 }}>{placed.shop.business_name}</Text>
          <Line label="Totale da pagare" value={formatEuro(placed.total_cents)} bold />
          <Line label="Ritiro oggi" value={`${placed.shop.pickup_from} – ${placed.shop.pickup_to}`} />
        </Card>

        {expired ? (
          <Text style={{ color: colors.error, fontWeight: '700', marginBottom: 12 }}>Il tempo per pagare è scaduto: le box non sono più riservate.</Text>
        ) : (
          <Text style={{ color: remaining < 60000 ? colors.error : colors.text, fontWeight: '600', marginBottom: 12 }}>
            Le box sono riservate per te ancora {formatCountdown(remaining)}
          </Text>
        )}

        {outcome ? <Text style={{ color: colors.error, fontWeight: '600', marginBottom: 12 }}>{outcome}</Text> : null}
        <ErrorText>{pay.error}</ErrorText>

        {placed.payment.simulated ? (
          <>
            <Hint>Modalità di prova: non serve nessuna carta. Il pagamento viene simulato dal server e passa dallo stesso percorso di uno vero.</Hint>
            <Button title={`Paga ${formatEuro(placed.total_cents)} (prova)`} onPress={() => payNow('succeeded')} loading={pay.loading} disabled={!canPay} />
            <Button title="Simula un pagamento fallito" secondary onPress={() => payNow('failed')} disabled={!canPay || pay.loading} />
          </>
        ) : (
          <Hint>Il pagamento con carta non è ancora disponibile in questa versione dell’app: servirà il modulo di Stripe. Le box restano riservate per il tempo indicato.</Hint>
        )}

        {expired || outcome ? <Button title="Torna al carrello" secondary onPress={() => navigation.navigate('Cart')} /> : null}
      </Screen>
    );
  }

  // ----- Riepilogo prima di ordinare -----
  if (cart.items.length === 0) {
    return (
      <Screen>
        <Title>Pagamento</Title>
        <Hint>Il carrello è vuoto.</Hint>
        <Button title="Cerca negozi" onPress={() => navigation.navigate('Shops')} />
      </Screen>
    );
  }

  const shop = cart.shop;
  const closed = Date.now() >= Date.parse(shop.order_deadline);

  return (
    <Screen>
      <Title>Riepilogo dell’ordine</Title>

      <Card>
        <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{shop.business_name}</Text>
        <Text style={{ color: colors.muted, marginBottom: 8 }}>{shop.address}</Text>
        <Line label="Ritiro oggi" value={`${shop.pickup_from} – ${shop.pickup_to}`} />
        {cart.items.map((i) => <Line key={i.box_id} label={`${i.quantity} × ${i.title}`} value={formatEuro(i.price_cents * i.quantity)} />)}
        <View style={{ height: 1, backgroundColor: colors.border, marginVertical: 8 }} />
        <Line label="Totale" value={formatEuro(totals.total_cents)} bold />
        <Line label="Risparmi almeno" value={formatEuro(totals.min_saving_cents)} color={colors.green} />
      </Card>

      {/* Avviso in evidenza, con casella obbligatoria (RB-09, US-C06) */}
      <Card style={{ borderColor: colors.error, borderWidth: 2 }}>
        <Text style={{ color: colors.error, fontWeight: '700', fontSize: 16, marginBottom: 6 }}>Attenzione: nessun rimborso</Text>
        <Text style={{ color: colors.text }}>
          Se non ritiri l’ordine nella fascia indicata ({shop.pickup_from}–{shop.pickup_to}) non è previsto alcun rimborso.
        </Text>
        <Pressable onPress={() => setAccepted(!accepted)} style={{ flexDirection: 'row', alignItems: 'center', marginTop: 12 }}>
          <View style={{
            width: 24, height: 24, borderRadius: 5, borderWidth: 2, borderColor: colors.error, marginRight: 10,
            backgroundColor: accepted ? colors.error : '#fff', alignItems: 'center', justifyContent: 'center',
          }}>
            {accepted ? <Text style={{ color: '#fff', fontWeight: '700' }}>✓</Text> : null}
          </View>
          <Text style={{ flex: 1, color: colors.text, fontWeight: '600' }}>Ho capito e accetto</Text>
        </Pressable>
      </Card>

      {closed ? <Text style={{ color: colors.error, fontWeight: '600', marginBottom: 12 }}>L’orario limite per ordinare da questo negozio è passato.</Text> : null}
      <ErrorText>{place.error}</ErrorText>
      {problem ? (
        <Card style={{ borderColor: colors.error }}>
          {shortageLines(problem, cart).map((line) => <Text key={line} style={{ color: colors.error, marginBottom: 4 }}>{line}</Text>)}
          <Text style={{ color: colors.text, marginTop: 4 }}>Non ti è stato addebitato nulla. Rivedi il carrello e riprova.</Text>
          <Button title="Rivedi il carrello" secondary onPress={() => navigation.navigate('Cart')} />
        </Card>
      ) : null}

      <Button title={`Conferma e paga ${formatEuro(totals.total_cents)}`} onPress={submit} loading={place.loading} disabled={!accepted || closed} />
      <LinkText onPress={() => navigation.goBack()}>Torna al carrello</LinkText>
    </Screen>
  );
}

// ---------------------------------------------------------------------------------------------
// Un ordine: conferma dopo il pagamento e dettaglio dallo storico.
// ---------------------------------------------------------------------------------------------
export function OrderScreen({ route, navigation }) {
  const { orderId, justPaid } = route.params;
  const [order, setOrder] = useState(null);
  const [error, setError] = useState('');
  const pay = useAction();

  const load = useCallback(async () => {
    try {
      setOrder(await api.order(orderId));
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, [orderId]);

  useEffect(() => { load(); }, [load]);

  if (!order) {
    return (
      <Screen>
        {error ? <><ErrorText>{error}</ErrorText><Button title="Riprova" onPress={load} /></> : <ActivityIndicator size="large" color={colors.green} />}
      </Screen>
    );
  }

  const pending = order.status === 'PENDING_PAYMENT';
  const paidNow = justPaid && order.status === 'PAID';
  const units = order.items.reduce((sum, i) => sum + i.quantity, 0);

  return (
    <Screen>
      {paidNow ? (
        <Card style={{ borderColor: colors.green, borderWidth: 2 }}>
          <Text style={{ color: colors.green, fontSize: 18, fontWeight: '700', marginBottom: 4 }}>Ordine confermato!</Text>
          <Text style={{ color: colors.text }}>Grazie per aver salvato delle piante dallo spreco. Ti abbiamo inviato un’email di conferma.</Text>
        </Card>
      ) : null}

      <Title>Ordine {order.number}</Title>
      <StatusBadge status={order.status} />

      <View style={{ height: 12 }} />
      <Card>
        <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{order.shop.business_name}</Text>
        <Text style={{ color: colors.text }}>{order.shop.address}</Text>
        <Text style={{ color: colors.muted, marginBottom: 8 }}>{order.shop.city}</Text>
        <Line label="Giorno" value={formatDate(order.order_date)} />
        <Line label="Ritiro" value={`${order.shop.pickup_from} – ${order.shop.pickup_to}`} />
      </Card>

      <Card>
        {order.items.map((i) => (
          <Line key={i.box_id} label={`${i.quantity} × ${i.description || i.box_type}`} value={formatEuro(i.line_total_cents)} />
        ))}
        <View style={{ height: 1, backgroundColor: colors.border, marginVertical: 8 }} />
        <Line label={`Totale (${units} box)`} value={formatEuro(order.total_cents)} bold />
        <Line label="Risparmi almeno" value={formatEuro(order.min_saving_cents)} color={colors.green} />
      </Card>

      {pending ? (
        <Card style={{ borderColor: '#9a6b00' }}>
          <Text style={{ color: '#9a6b00', fontWeight: '700', marginBottom: 4 }}>In attesa di pagamento</Text>
          <Text style={{ color: colors.text }}>Le box sono riservate per te fino alle {localTimeOf(order.reserved_until)}.</Text>
          <ErrorText>{pay.error}</ErrorText>
          {order.payment_simulated ? (
            <Button
              title={`Paga ${formatEuro(order.total_cents)} (prova)`}
              loading={pay.loading}
              onPress={() => pay.run(async () => { await api.simulatePayment(order.id, 'succeeded'); await load(); })}
            />
          ) : <Hint>Il pagamento con carta non è ancora disponibile in questa versione dell’app.</Hint>}
        </Card>
      ) : null}

      {order.status === 'PAID' || order.status === 'PICKED_UP' ? <PickupTicket order={order} /> : null}
      {order.status === 'PAID' ? (
        <Text style={{ color: colors.error, fontWeight: '600', marginBottom: 12 }}>
          Promemoria: se non ritiri l’ordine nella fascia indicata non è previsto alcun rimborso.
        </Text>
      ) : null}
      {order.status === 'PICKED_UP' ? (
        <Text style={{ color: colors.green, fontWeight: '600', marginBottom: 12 }}>Ordine ritirato alle {localTimeOf(order.picked_up_at)}. Buon verde!</Text>
      ) : null}
      {order.status === 'NOT_PICKED_UP' ? (
        <Text style={{ color: colors.error, fontWeight: '600', marginBottom: 12 }}>
          Non hai ritirato l’ordine nella fascia indicata ({order.shop.pickup_from}–{order.shop.pickup_to}): come previsto, non c’è rimborso.
        </Text>
      ) : null}
      {order.status === 'REFUNDED_FULL' ? <Hint>Questo ordine è stato rimborsato per intero.</Hint> : null}

      <Button title="Torna ai negozi" onPress={() => navigation.navigate('Shops')} secondary={!paidNow} />
      <LinkText onPress={() => navigation.navigate('Orders')}>Vedi tutti i miei ordini</LinkText>
    </Screen>
  );
}

// ---------------------------------------------------------------------------------------------
// Storico degli ordini (US-C11): attivi e passati, con lo stato.
// ---------------------------------------------------------------------------------------------
export function OrdersScreen({ navigation }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      setData(await api.orders());
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);

  // Si aggiorna ogni volta che si torna qui (dopo un pagamento, per esempio)
  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <Screen>
      <Title>I miei ordini</Title>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}
      {data?.data.length === 0 ? <Hint>Non hai ancora fatto ordini. Cerca un negozio e salva qualche pianta!</Hint> : null}

      {data?.data.map((order) => {
        const units = order.items.reduce((sum, i) => sum + i.quantity, 0);

        return (
          <Pressable key={order.id} onPress={() => navigation.navigate('Order', { orderId: order.id })}>
            <Card>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                <Text style={{ fontWeight: '700', color: colors.text }}>{order.number}</Text>
                <StatusBadge status={order.status} />
              </View>
              <Text style={{ fontSize: 16, color: colors.text }}>{order.shop.business_name}</Text>
              <Text style={{ color: colors.muted }}>
                {formatDate(order.order_date)} · ritiro {order.shop.pickup_from}–{order.shop.pickup_to}
              </Text>
              <Text style={{ color: colors.text, marginTop: 4 }}>{units} box · {formatEuro(order.total_cents)}</Text>
            </Card>
          </Pressable>
        );
      })}

      <LinkText onPress={load}>Aggiorna</LinkText>
    </Screen>
  );
}
