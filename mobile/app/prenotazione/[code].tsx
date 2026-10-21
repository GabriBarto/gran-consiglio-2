import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import QRCode from 'react-native-qrcode-svg';
import { api, Reservation } from '../../lib/api';
import { slot } from '../../lib/format';
import { colors } from '../../lib/theme';
import { ErrorText, Tag } from '../../components/ui';

export default function Codice() {
  const { code } = useLocalSearchParams<{ code: string }>();
  const [r, setR] = useState<Reservation | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { api<Reservation>(`/api/reservations/${code}`).then(setR).catch((e) => setError(e.message)); }, [code]);

  return (
    <ScrollView contentContainerStyle={{ padding: 20, alignItems: 'center' }}>
      {r && <Text style={{ fontSize: 22, fontWeight: '800', textAlign: 'center' }}>Prenotazione confermata 🎉</Text>}
      <View style={s.qr}><QRCode value={String(code)} size={200} /></View>
      <Text style={s.code}>{code}</Text>
      <Text style={s.hint}>Mostra questo codice in negozio</Text>
      <ErrorText>{error}</ErrorText>
      {r && (
        <View style={s.info}>
          <Tag done={r.status === 'collected'}>{r.status === 'collected' ? 'Ritirata' : 'Da ritirare'}</Tag>
          <Text style={s.title}>{r.emoji} {r.title} × {r.qty}</Text>
          <Text style={s.meta}>{r.shop_name} · {r.address}, {r.city}</Text>
          <Text style={s.meta}>🕒 {slot(r.pickup_start, r.pickup_end)}</Text>
        </View>
      )}
    </ScrollView>
  );
}

const s = StyleSheet.create({
  qr: { backgroundColor: '#fff', padding: 18, borderRadius: 20, marginVertical: 18, borderWidth: 1, borderColor: colors.line },
  code: { fontSize: 30, fontWeight: '800', letterSpacing: 3, color: colors.greenDark, backgroundColor: colors.leaf, paddingHorizontal: 16, paddingVertical: 6, borderRadius: 12, overflow: 'hidden' },
  hint: { color: colors.muted, marginTop: 8 },
  info: { alignSelf: 'stretch', backgroundColor: '#fff', borderRadius: 16, borderWidth: 1, borderColor: colors.line, padding: 16, marginTop: 20, gap: 6 },
  title: { fontSize: 18, fontWeight: '700' },
  meta: { color: colors.muted },
});
