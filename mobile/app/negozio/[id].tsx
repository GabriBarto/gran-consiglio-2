import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { api, Offer, Shop } from '../../lib/api';
import { colors } from '../../lib/theme';
import { OfferCard } from '../../components/OfferCard';
import { Empty, ErrorText } from '../../components/ui';

export default function NegozioPagina() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [shop, setShop] = useState<(Shop & { boxes: Offer[] }) | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { api<Shop & { boxes: Offer[] }>(`/api/shops/${id}`).then(setShop).catch((e) => setError(e.message)); }, [id]);

  if (!shop) return <ErrorText>{error}</ErrorText>;
  const hours = (a: string | null, b: string | null) => (a && b ? `${a}–${b}` : 'non indicato');
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <Text style={s.title}>{shop.emoji} {shop.name}</Text>
      <Text style={s.desc}>{shop.description}</Text>
      <Text style={s.meta}>📍 {shop.address}, {shop.city}{shop.phone ? ` · 📞 ${shop.phone}` : ''}</Text>
      <Text style={s.meta}>🕘 Apertura: {hours(shop.opening_time, shop.closing_time)}</Text>
      <Text style={s.meta}>🕒 Ritiro: {hours(shop.pickup_start, shop.pickup_end)}</Text>
      <Text style={[s.title, { fontSize: 18, marginVertical: 14 }]}>Sorprese disponibili</Text>
      {shop.boxes.length ? shop.boxes.map((o) => <OfferCard key={o.id} o={o} />) : <Empty>Nessuna sorpresa al momento 🌧️</Empty>}
    </ScrollView>
  );
}

const s = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '800', color: colors.ink },
  desc: { marginTop: 8, fontSize: 15 },
  meta: { color: colors.muted, marginTop: 6 },
});
