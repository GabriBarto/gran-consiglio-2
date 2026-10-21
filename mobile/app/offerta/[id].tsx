import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { api, Cart, Offer } from '../../lib/api';
import { eur, slot } from '../../lib/format';
import { getSession, setNext } from '../../lib/storage';
import { colors } from '../../lib/theme';
import { Button, ErrorText } from '../../components/ui';

export default function OffertaDettaglio() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [o, setO] = useState<Offer | null>(null);
  const [qty, setQty] = useState(1);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { api<Offer>(`/api/offers/${id}`).then(setO).catch((e) => setError(e.message)); }, [id]);

  async function addToCart() {
    setBusy(true); setError('');
    try {
      const session = await getSession();
      if (!session) {
        // non registrato: va a registrarsi e poi torna qui
        await setNext(`/offerta/${id}`);
        router.dismissTo('/(tabs)/venditori');
        return;
      }
      if (session.user.role !== 'cliente') throw new Error('Solo gli account cliente possono acquistare');
      const cart = await api<Cart>('/api/cart', { token: session.token });
      const have = cart.items.find((i) => i.box_id === Number(id))?.qty ?? 0;
      await api<Cart>('/api/cart/items', { method: 'POST', token: session.token, body: { box_id: Number(id), qty: have + qty } });
      router.dismissTo('/(tabs)/carrello');
    } catch (e) {
      // un carrello di un altro negozio va svuotato prima: lo si fa con la schermata Carrello
      setError((e as Error).message);
    } finally { setBusy(false); }
  }

  if (!o) return <View style={{ padding: 20 }}><ErrorText>{error}</ErrorText></View>;
  const max = Math.max(1, Math.min(5, o.available));
  return (
    <ScrollView contentContainerStyle={{ padding: 20 }}>
      <Text style={{ fontSize: 56, textAlign: 'center' }}>{o.emoji}</Text>
      <Text style={s.title}>{o.title}</Text>
      <Text style={s.meta} onPress={() => router.push(`/negozio/${o.shop_id}`)}>{o.shop_emoji} {o.shop_name} · {o.address}, {o.city}</Text>
      <Text style={s.desc}>{o.description}</Text>
      {!!o.allergens?.length && <Text style={s.meta}>⚠️ Allergeni: {o.allergens.join(', ')}</Text>}
      <Text style={s.meta}>🕒 Ritiro {slot(o.pickup_start, o.pickup_end)}</Text>
      <Text style={s.price}>{eur(o.price_cents)} <Text style={s.old}>{eur(o.original_price_cents)}</Text></Text>
      <Text style={s.meta}>Disponibili: {o.available}</Text>

      <View style={s.qty}>
        <Button title="−" variant="secondary" onPress={() => setQty(Math.max(1, qty - 1))} style={s.qtyBtn} />
        <Text style={s.qtyN}>{qty}</Text>
        <Button title="+" variant="secondary" onPress={() => setQty(Math.min(max, qty + 1))} style={s.qtyBtn} />
      </View>
      <ErrorText>{error}</ErrorText>
      <Button title={`Aggiungi al carrello · ${eur(o.price_cents * qty)}`} onPress={addToCart} loading={busy} disabled={o.available < 1} />
    </ScrollView>
  );
}

const s = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '800', color: colors.ink, textAlign: 'center', marginTop: 6 },
  meta: { color: colors.muted, marginTop: 6 },
  desc: { color: colors.ink, marginTop: 10, fontSize: 15 },
  price: { fontSize: 28, fontWeight: '800', color: colors.greenDark, marginTop: 10 },
  old: { fontSize: 16, color: colors.muted, textDecorationLine: 'line-through', fontWeight: '400' },
  qty: { flexDirection: 'row', alignItems: 'center', gap: 16, marginVertical: 14 },
  qtyBtn: { width: 52, paddingHorizontal: 0 },
  qtyN: { fontSize: 22, fontWeight: '700', minWidth: 24, textAlign: 'center' },
});
