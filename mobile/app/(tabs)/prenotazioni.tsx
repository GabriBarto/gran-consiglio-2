import { useCallback, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, Order, Session } from '../../lib/api';
import { eur, slot } from '../../lib/format';
import { getSession, setNext } from '../../lib/storage';
import { colors, radius } from '../../lib/theme';
import { Button, Empty, ErrorText, Tag } from '../../components/ui';

export default function Ordini() {
  const [session, setSession] = useState<Session | null>(null);
  const [items, setItems] = useState<Order[]>([]);
  const [error, setError] = useState('');

  useFocusEffect(useCallback(() => {
    (async () => {
      const s = await getSession();
      setSession(s);
      if (s?.user.role !== 'cliente') return;
      try { setItems(await api<Order[]>('/api/orders', { token: s.token })); setError(''); } catch (e) { setError((e as Error).message); }
    })();
  }, []));

  if (!session) {
    return (
      <View style={{ padding: 16 }}>
        <Empty>Accedi per vedere i tuoi ordini 🎟️</Empty>
        <Button title="Accedi / Registrati" onPress={async () => { await setNext('/(tabs)/prenotazioni'); router.navigate('/(tabs)/venditori'); }} />
      </View>
    );
  }
  if (session.user.role !== 'cliente') return <View style={{ padding: 16 }}><Empty>Solo gli account cliente hanno ordini.</Empty></View>;

  return (
    <FlatList
      data={items}
      keyExtractor={(r) => r.code}
      contentContainerStyle={{ padding: 16 }}
      ListHeaderComponent={<ErrorText>{error}</ErrorText>}
      ListEmptyComponent={<Empty>Nessun ordine ancora 🌱</Empty>}
      renderItem={({ item: r }) => (
        <Pressable style={s.card} onPress={() => router.push(`/prenotazione/${r.code}`)}>
          <Tag done={r.status === 'collected'}>{r.status === 'collected' ? 'Ritirato' : 'Pagato · da ritirare'}</Tag>
          {r.items.map((i, k) => <Text key={k} style={s.title}>{i.emoji} {i.qty}× {i.title}</Text>)}
          <Text style={s.meta}>{r.shop_name} · {r.city} · {eur(r.total_cents)}</Text>
          <Text style={s.meta}>🕒 {slot(r.items[0].pickup_start, r.items[0].pickup_end)}</Text>
          <Text style={s.code}>{r.code}</Text>
        </Pressable>
      )}
    />
  );
}

const s = StyleSheet.create({
  card: { backgroundColor: '#fff', borderRadius: radius, borderWidth: 1, borderColor: colors.line, padding: 14, marginBottom: 12, gap: 4 },
  title: { fontSize: 17, fontWeight: '700' },
  meta: { color: colors.muted },
  code: { fontWeight: '800', letterSpacing: 2, color: colors.greenDark, fontSize: 18, marginTop: 4 },
});
