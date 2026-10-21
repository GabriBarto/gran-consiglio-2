import { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, Cart, Session } from '../../lib/api';
import { eur, slot } from '../../lib/format';
import { getSession, setNext } from '../../lib/storage';
import { colors } from '../../lib/theme';
import { Button, Empty, ErrorText, Field, Panel } from '../../components/ui';

export default function Carrello() {
  const [session, setSession] = useState<Session | null>(null);
  const [cart, setCart] = useState<Cart | null>(null);
  const [card, setCard] = useState({ number: '4242 4242 4242 4242', expiry: '12/30' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const s = await getSession();
    setSession(s);
    if (s?.user.role === 'cliente') {
      try { setCart(await api<Cart>('/api/cart', { token: s.token })); setError(''); } catch (e) { setError((e as Error).message); }
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function setQty(boxId: number, qty: number) {
    try { setCart(await api<Cart>('/api/cart/items', { method: 'POST', token: session!.token, body: { box_id: boxId, qty } })); setError(''); }
    catch (e) { setError((e as Error).message); }
  }

  async function pay() {
    setBusy(true); setError('');
    try {
      const { code } = await api<{ code: string }>('/api/checkout', { method: 'POST', token: session!.token, body: { card } });
      setCart(null);
      router.push(`/prenotazione/${code}`);
    } catch (e) { setError((e as Error).message); load(); } finally { setBusy(false); }
  }

  if (!session) {
    return (
      <View style={{ padding: 16 }}>
        <Empty>Accedi o registrati per usare il carrello 🛒</Empty>
        <Button title="Accedi / Registrati" onPress={async () => { await setNext('/(tabs)/carrello'); router.navigate('/(tabs)/venditori'); }} />
      </View>
    );
  }
  if (session.user.role !== 'cliente') return <View style={{ padding: 16 }}><Empty>Solo gli account cliente hanno un carrello.</Empty></View>;
  if (!cart?.items.length) return <View style={{ padding: 16 }}><Empty>Il carrello è vuoto 🌱</Empty><ErrorText>{error}</ErrorText></View>;

  const allOk = cart.items.every((i) => i.ok);
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Text style={s.shop}>Ordine da {cart.shop?.name} · {cart.shop?.address}, {cart.shop?.city}</Text>
      {cart.items.map((i) => (
        <Panel key={i.box_id} style={{ marginTop: 10 }}>
          <Text style={s.title}>{i.emoji} {i.title}</Text>
          <Text style={s.meta}>🕒 {slot(i.pickup_start, i.pickup_end)}</Text>
          {!i.ok && <Text style={{ color: colors.danger }}>Non più disponibile nella quantità scelta</Text>}
          <View style={s.row}>
            <Button title="−" variant="secondary" onPress={() => setQty(i.box_id, i.qty - 1)} style={s.qtyBtn} />
            <Text style={s.qtyN}>{i.qty}</Text>
            <Button title="+" variant="secondary" onPress={() => setQty(i.box_id, Math.min(5, i.qty + 1))} style={s.qtyBtn} />
            <Text style={s.sum}>{eur(i.qty * i.price_cents)}</Text>
          </View>
        </Panel>
      ))}
      <Text style={s.total}>Totale {eur(cart.total_cents)} <Text style={s.meta}>(risparmi {eur(cart.savings_cents)})</Text></Text>
      <Panel>
        <Text style={s.title}>Pagamento</Text>
        <Text style={s.meta}>Pagamento simulato: usa una carta di 16 cifre qualsiasi.</Text>
        <Field label="Numero di carta" value={card.number} onChangeText={(v) => setCard({ ...card, number: v })} keyboardType="number-pad" />
        <Field label="Scadenza (MM/AA)" value={card.expiry} onChangeText={(v) => setCard({ ...card, expiry: v })} />
        <ErrorText>{error}</ErrorText>
        <Button title={`Paga ${eur(cart.total_cents)}`} onPress={pay} loading={busy} disabled={!allOk} />
        <View style={{ height: 10 }} />
        <Button title="Svuota carrello" variant="secondary" onPress={() => Alert.alert('Svuotare il carrello?', undefined, [
          { text: 'No' }, { text: 'Sì', onPress: async () => setCart(await api<Cart>('/api/cart', { method: 'DELETE', token: session.token })) }])} />
      </Panel>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  shop: { color: colors.muted },
  title: { fontSize: 17, fontWeight: '700' },
  meta: { color: colors.muted },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 8 },
  qtyBtn: { width: 44, paddingHorizontal: 0 },
  qtyN: { fontSize: 18, fontWeight: '700', minWidth: 20, textAlign: 'center' },
  sum: { marginLeft: 'auto', fontWeight: '800', color: colors.greenDark, fontSize: 17 },
  total: { fontSize: 22, fontWeight: '800', marginVertical: 14 },
});
