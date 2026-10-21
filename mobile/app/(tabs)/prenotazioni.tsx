import { useCallback, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, Reservation } from '../../lib/api';
import { slot } from '../../lib/format';
import { getCodes } from '../../lib/storage';
import { colors, radius } from '../../lib/theme';
import { Button, Empty, ErrorText, Field, Tag } from '../../components/ui';

export default function Prenotazioni() {
  const [items, setItems] = useState<Reservation[]>([]);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');

  useFocusEffect(useCallback(() => {
    (async () => {
      const codes = await getCodes();
      const all = await Promise.all(codes.map((c) => api<Reservation>(`/api/reservations/${c}`).catch(() => null)));
      setItems(all.filter((r): r is Reservation => r !== null));
    })();
  }, []));

  async function search() {
    try { await api(`/api/reservations/${code.trim().toUpperCase()}`); setError(''); router.push(`/prenotazione/${code.trim().toUpperCase()}`); }
    catch (e) { setError((e as Error).message); }
  }

  return (
    <FlatList
      data={items}
      keyExtractor={(r) => r.code}
      contentContainerStyle={{ padding: 16 }}
      ListHeaderComponent={
        <View style={{ marginBottom: 12 }}>
          <Field label="Hai un codice? Cercalo" value={code} onChangeText={setCode} autoCapitalize="characters" placeholder="VS-XXXXXX" />
          <ErrorText>{error}</ErrorText>
          <Button title="Cerca" variant="secondary" onPress={search} />
        </View>
      }
      ListEmptyComponent={<Empty>Nessuna prenotazione ancora 🌱</Empty>}
      renderItem={({ item: r }) => (
        <Pressable style={s.card} onPress={() => router.push(`/prenotazione/${r.code}`)}>
          <Tag done={r.status === 'collected'}>{r.status === 'collected' ? 'Ritirata' : 'Da ritirare'}</Tag>
          <Text style={s.title}>{r.emoji} {r.title} × {r.qty}</Text>
          <Text style={s.meta}>{r.shop_name} · {r.city}</Text>
          <Text style={s.meta}>🕒 {slot(r.pickup_start, r.pickup_end)}</Text>
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
