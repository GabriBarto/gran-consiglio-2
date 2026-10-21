import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { api, Offer } from '../../lib/api';
import { eur, slot } from '../../lib/format';
import { addCode } from '../../lib/storage';
import { colors } from '../../lib/theme';
import { Button, ErrorText, Field } from '../../components/ui';

export default function Prenota() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [o, setO] = useState<Offer | null>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [qty, setQty] = useState(1);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { api<Offer>(`/api/offers/${id}`).then(setO).catch((e) => setError(e.message)); }, [id]);

  async function submit() {
    setBusy(true); setError('');
    try {
      const { code } = await api<{ code: string }>('/api/reservations', { method: 'POST', body: { offer_id: Number(id), name, email, qty } });
      await addCode(code);
      router.replace(`/prenotazione/${code}`);
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }

  if (!o) return <View style={{ padding: 20 }}><ErrorText>{error}</ErrorText></View>;
  const max = Math.min(5, o.available);
  return (
    <ScrollView contentContainerStyle={{ padding: 20 }} keyboardShouldPersistTaps="handled">
      <Text style={{ fontSize: 56, textAlign: 'center' }}>{o.emoji}</Text>
      <Text style={s.title}>{o.title}</Text>
      <Text style={s.meta}>{o.shop_emoji} {o.shop_name} · {o.address}, {o.city}</Text>
      <Text style={s.desc}>{o.description}</Text>
      <Text style={s.meta}>🕒 Ritiro {slot(o.pickup_start, o.pickup_end)}</Text>
      <Text style={s.price}>{eur(o.price_cents)} <Text style={s.old}>{eur(o.original_price_cents)}</Text></Text>
      <Text style={s.meta}>Paghi in negozio al ritiro.</Text>

      <View style={{ height: 16 }} />
      <Field label="Nome" value={name} onChangeText={setName} autoComplete="name" />
      <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" />
      <Text style={{ fontWeight: '600', fontSize: 13, marginBottom: 6 }}>Quantità</Text>
      <View style={s.qty}>
        <Button title="−" variant="secondary" onPress={() => setQty(Math.max(1, qty - 1))} style={s.qtyBtn} />
        <Text style={s.qtyN}>{qty}</Text>
        <Button title="+" variant="secondary" onPress={() => setQty(Math.min(max, qty + 1))} style={s.qtyBtn} />
      </View>
      <ErrorText>{error}</ErrorText>
      <Button title={`Conferma prenotazione · ${eur(o.price_cents * qty)}`} onPress={submit} loading={busy} />
    </ScrollView>
  );
}

const s = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '800', color: colors.ink, textAlign: 'center', marginTop: 6 },
  meta: { color: colors.muted, marginTop: 6 },
  desc: { color: colors.ink, marginTop: 10, fontSize: 15 },
  price: { fontSize: 28, fontWeight: '800', color: colors.greenDark, marginTop: 10 },
  old: { fontSize: 16, color: colors.muted, textDecorationLine: 'line-through', fontWeight: '400' },
  qty: { flexDirection: 'row', alignItems: 'center', gap: 16, marginBottom: 8 },
  qtyBtn: { width: 52, paddingHorizontal: 0 },
  qtyN: { fontSize: 22, fontWeight: '700', minWidth: 24, textAlign: 'center' },
});
