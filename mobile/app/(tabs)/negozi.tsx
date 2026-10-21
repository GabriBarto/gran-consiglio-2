import { useCallback, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, TextInput } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, Shop } from '../../lib/api';
import { colors, radius } from '../../lib/theme';
import { Empty, ErrorText } from '../../components/ui';

export default function Negozi() {
  const [shops, setShops] = useState<Shop[]>([]);
  const [q, setQ] = useState('');
  const [error, setError] = useState('');

  useFocusEffect(useCallback(() => {
    api<Shop[]>(`/api/shops?${new URLSearchParams({ q })}`).then((l) => { setShops(l); setError(''); }).catch((e) => setError(e.message));
  }, [q]));

  return (
    <FlatList
      data={shops}
      keyExtractor={(x) => String(x.id)}
      contentContainerStyle={{ padding: 16 }}
      ListHeaderComponent={<>
        <TextInput style={s.search} placeholder="Cerca per nome o città…" value={q} onChangeText={setQ} />
        <ErrorText>{error}</ErrorText>
      </>}
      ListEmptyComponent={error ? undefined : <Empty>Nessun negozio trovato 🌧️</Empty>}
      renderItem={({ item: x }) => (
        <Pressable style={s.card} onPress={() => router.push(`/negozio/${x.id}`)}>
          <Text style={s.title}>{x.emoji} {x.name}</Text>
          <Text style={s.meta}>{x.address}, {x.city}</Text>
          <Text style={s.meta}>{x.open_boxes} sorprese disponibili</Text>
        </Pressable>
      )}
    />
  );
}

const s = StyleSheet.create({
  search: { borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 10, fontSize: 16, marginBottom: 10 },
  card: { backgroundColor: '#fff', borderRadius: radius, borderWidth: 1, borderColor: colors.line, padding: 14, marginBottom: 12, gap: 4 },
  title: { fontSize: 18, fontWeight: '700' },
  meta: { color: colors.muted },
});
