import { useCallback, useState } from 'react';
import { FlatList, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { api, Offer, Stats } from '../../lib/api';
import { eur } from '../../lib/format';
import { CATEGORIES, colors } from '../../lib/theme';
import { OfferCard } from '../../components/OfferCard';
import { Chip, Empty, ErrorText } from '../../components/ui';

export default function Scopri() {
  const [offers, setOffers] = useState<Offer[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [cities, setCities] = useState<string[]>([]);
  const [q, setQ] = useState('');
  const [city, setCity] = useState('');
  const [cat, setCat] = useState('');
  const [error, setError] = useState('');
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const p = new URLSearchParams({ q, city, category: cat });
      const [o, st, f] = await Promise.all([
        api<Offer[]>(`/api/offers?${p}`), api<Stats>('/api/stats'), api<{ cities: string[] }>('/api/filters'),
      ]);
      setOffers(o); setStats(st); setCities(f.cities); setError('');
    } catch (e) { setError((e as Error).message); }
  }, [q, city, cat]);

  // ricarica a ogni apertura della schermata e quando cambiano i filtri
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const header = (
    <View>
      <View style={s.hero}>
        <Text style={s.heroTitle}>Ogni pianta merita una seconda possibilità</Text>
        {stats && (
          <View style={s.stats}>
            <Stat n={String(stats.plantsSaved)} l="piante salvate" />
            <Stat n={String(stats.shops)} l="negozi" />
            <Stat n={eur(stats.moneySavedCents)} l="risparmiati" />
          </View>
        )}
      </View>
      <TextInput style={s.search} placeholder="Cerca: lavanda, orchidea, bouquet…" value={q} onChangeText={setQ} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 8 }}>
        <Chip label="Tutte le città" active={!city} onPress={() => setCity('')} />
        {cities.map((c) => <Chip key={c} label={c} active={city === c} onPress={() => setCity(c)} />)}
      </ScrollView>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 14 }}>
        <Chip label="Tutto" active={!cat} onPress={() => setCat('')} />
        {Object.entries(CATEGORIES).map(([k, v]) => <Chip key={k} label={v} active={cat === k} onPress={() => setCat(k)} />)}
      </ScrollView>
      <ErrorText>{error}</ErrorText>
    </View>
  );

  return (
    <FlatList
      data={offers}
      keyExtractor={(o) => String(o.id)}
      renderItem={({ item }) => <OfferCard o={item} />}
      ListHeaderComponent={header}
      ListEmptyComponent={error ? undefined : <Empty>Nessuna offerta al momento 🌧️</Empty>}
      contentContainerStyle={{ padding: 16 }}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} />}
    />
  );
}

const Stat = ({ n, l }: { n: string; l: string }) => (
  <View style={s.stat}><Text style={s.statN}>{n}</Text><Text style={s.statL}>{l}</Text></View>
);

const s = StyleSheet.create({
  hero: { backgroundColor: colors.green, borderRadius: 20, padding: 18, marginBottom: 14 },
  heroTitle: { color: '#fff', fontSize: 24, fontWeight: '800' },
  stats: { flexDirection: 'row', gap: 8, marginTop: 14 },
  stat: { backgroundColor: 'rgba(255,255,255,.18)', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 8, flexShrink: 1 },
  statN: { color: '#fff', fontWeight: '800', fontSize: 17 },
  statL: { color: '#fff', fontSize: 11, opacity: 0.9 },
  search: { borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 10, fontSize: 16, marginBottom: 10 },
});
