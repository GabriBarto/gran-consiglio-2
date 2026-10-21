import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import type { Offer } from '../lib/api';
import { eur, slot } from '../lib/format';
import { colors, radius } from '../lib/theme';

export function OfferCard({ o }: { o: Offer }) {
  const off = Math.round((1 - o.price_cents / o.original_price_cents) * 100);
  const low = o.available <= 2;
  return (
    <Pressable style={s.card} onPress={() => router.push(`/offerta/${o.id}`)}>
      <View style={s.pic}>
        <Text style={{ fontSize: 56 }}>{o.emoji}</Text>
        <View style={[s.badge, low && { backgroundColor: colors.danger }]}>
          <Text style={s.badgeText}>{low ? `Ultime ${o.available}!` : `-${off}%`}</Text>
        </View>
      </View>
      <View style={s.body}>
        <Text style={s.shop}>{o.shop_emoji} {o.shop_name} · {o.city}</Text>
        <Text style={s.title}>{o.title}</Text>
        <Text style={s.meta}>🕒 {slot(o.pickup_start, o.pickup_end)}</Text>
        <View style={s.priceRow}>
          <Text style={s.price}>{eur(o.price_cents)}</Text>
          <Text style={s.old}>{eur(o.original_price_cents)}</Text>
          <Text style={[s.meta, { marginLeft: 'auto' }]}>{o.available} disponibili</Text>
        </View>
      </View>
    </Pressable>
  );
}

const s = StyleSheet.create({
  card: { backgroundColor: '#fff', borderRadius: radius, borderWidth: 1, borderColor: colors.line, overflow: 'hidden', marginBottom: 14 },
  pic: { backgroundColor: colors.leaf, alignItems: 'center', paddingVertical: 18 },
  badge: { position: 'absolute', top: 10, right: 10, backgroundColor: colors.accent, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText: { color: '#fff', fontWeight: '700', fontSize: 12 },
  body: { padding: 14, gap: 3 },
  shop: { color: colors.muted, fontSize: 13 },
  title: { fontSize: 18, fontWeight: '700', color: colors.ink },
  meta: { color: colors.muted, fontSize: 13 },
  priceRow: { flexDirection: 'row', alignItems: 'baseline', gap: 8, marginTop: 4 },
  price: { fontSize: 22, fontWeight: '800', color: colors.greenDark },
  old: { textDecorationLine: 'line-through', color: colors.muted },
});
