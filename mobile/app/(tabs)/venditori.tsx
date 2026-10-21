import { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, ApiError, Dashboard, SellerSession, Shop } from '../../lib/api';
import { eur, day, hhmm } from '../../lib/format';
import { takeScannedCode } from '../../lib/scan';
import { getSeller, setSeller } from '../../lib/storage';
import { CATEGORIES, colors } from '../../lib/theme';
import { Button, Chip, ErrorText, Field, Panel, Tag } from '../../components/ui';

export default function Venditori() {
  const [session, setSession] = useState<SellerSession | null>(null);
  const [ready, setReady] = useState(false);

  useFocusEffect(useCallback(() => { getSeller().then((s) => { setSession(s); setReady(true); }); }, []));

  if (!ready) return <View />;
  if (!session) return <Login onLogin={async (s) => { await setSeller(s); setSession(s); }} />;
  return <SellerDashboard session={session} onLogout={async () => { await setSeller(null); setSession(null); }} />;
}

function Login({ onLogin }: { onLogin: (s: SellerSession) => void }) {
  const [shops, setShops] = useState<Shop[]>([]);
  const [shopId, setShopId] = useState<number | null>(null);
  const [pin, setPin] = useState('');
  const [error, setError] = useState('');

  useFocusEffect(useCallback(() => {
    api<Shop[]>('/api/shops').then((s) => { setShops(s); setShopId((cur) => cur ?? s[0]?.id ?? null); setError(''); })
      .catch((e) => setError(e.message));
  }, []));

  async function login() {
    try { onLogin(await api<SellerSession>('/api/seller/login', { method: 'POST', body: { shop_id: shopId, pin } })); }
    catch (e) { setError((e as Error).message); }
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Text style={s.h1}>Area venditori</Text>
      <Text style={s.muted}>Trasforma l'invenduto in ricavo: pubblica una sorpresa verde in meno di un minuto.</Text>
      <Panel style={{ marginTop: 16 }}>
        <Text style={s.label}>Il tuo negozio</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
          {shops.map((x) => <Chip key={x.id} label={`${x.emoji} ${x.name}`} active={shopId === x.id} onPress={() => setShopId(x.id)} />)}
        </ScrollView>
        <Field label="PIN" value={pin} onChangeText={setPin} secureTextEntry keyboardType="number-pad" placeholder="Demo: 1111, 2222, 3333…" />
        <ErrorText>{error}</ErrorText>
        <Button title="Accedi" onPress={login} />
      </Panel>
    </ScrollView>
  );
}

function SellerDashboard({ session, onLogout }: { session: SellerSession; onLogout: () => void }) {
  const [d, setD] = useState<Dashboard | null>(null);
  const [code, setCode] = useState('');
  const [rdError, setRdError] = useState('');
  const [f, setF] = useState({ title: '', description: '', category: 'piante', original_price: '', price: '', quantity: '5', pickup_hours: '4' });
  const [nError, setNError] = useState('');
  const token = session.token;

  const load = useCallback(async () => {
    try { setD(await api<Dashboard>('/api/seller/dashboard', { token })); }
    catch (e) { if (e instanceof ApiError && e.status === 401) onLogout(); }
  }, [token, onLogout]);

  const redeem = useCallback(async (c: string) => {
    try {
      const r = await api<{ name: string; qty: number; title: string }>('/api/seller/redeem', { method: 'POST', token, body: { code: c } });
      setRdError(''); setCode('');
      Alert.alert('Ritiro confermato ✅', `${r.name} · ${r.qty}× ${r.title}`);
      load();
    } catch (e) { setRdError((e as Error).message); }
  }, [token, load]);

  // al rientro dallo scanner conferma automaticamente il codice letto
  useFocusEffect(useCallback(() => {
    load();
    const scanned = takeScannedCode();
    if (scanned) redeem(scanned);
  }, [load, redeem]));

  async function publish() {
    try {
      await api('/api/seller/offers', { method: 'POST', token, body: f });
      setNError(''); setF({ ...f, title: '', description: '', original_price: '', price: '' });
      Alert.alert('Offerta pubblicata 🌱'); load();
    } catch (e) { setNError((e as Error).message); }
  }
  const set = (k: keyof typeof f) => (v: string) => setF({ ...f, [k]: v });

  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <View style={s.row}>
        <Text style={s.h1}>{session.shop.emoji} {session.shop.name}</Text>
      </View>
      <Button title="Esci" variant="secondary" onPress={onLogout} style={{ alignSelf: 'flex-start', marginBottom: 12, paddingVertical: 8 }} />

      <View style={s.stats}>
        <Panel style={s.stat}><Text style={s.statN}>{d?.plantsSaved ?? '–'}</Text><Text style={s.muted}>piante salvate</Text></Panel>
        <Panel style={s.stat}><Text style={s.statN}>{d ? eur(d.revenueCents) : '–'}</Text><Text style={s.muted}>ricavi recuperati</Text></Panel>
      </View>

      <Panel>
        <Text style={s.h2}>Conferma ritiro</Text>
        <Button title="📷 Scansiona QR cliente" onPress={() => router.push('/scanner')} />
        <View style={{ height: 12 }} />
        <Field label="…oppure inserisci il codice" value={code} onChangeText={setCode} autoCapitalize="characters" placeholder="VS-XXXXXX" />
        <ErrorText>{rdError}</ErrorText>
        <Button title="Conferma" variant="secondary" onPress={() => redeem(code)} />
      </Panel>

      <Panel>
        <Text style={s.h2}>Nuova sorpresa verde</Text>
        <Field label="Titolo" value={f.title} onChangeText={set('title')} placeholder="Es. Sorpresa Aromatiche" />
        <Field label="Descrizione" value={f.description} onChangeText={set('description')} multiline />
        <Text style={s.label}>Categoria</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
          {Object.entries(CATEGORIES).map(([k, v]) => <Chip key={k} label={v} active={f.category === k} onPress={() => set('category')(k)} />)}
        </ScrollView>
        <View style={s.row}>
          <View style={{ flex: 1 }}><Field label="Prezzo pieno €" value={f.original_price} onChangeText={set('original_price')} keyboardType="decimal-pad" /></View>
          <View style={{ flex: 1 }}><Field label="Salvataggio €" value={f.price} onChangeText={set('price')} keyboardType="decimal-pad" /></View>
        </View>
        <View style={s.row}>
          <View style={{ flex: 1 }}><Field label="Quantità" value={f.quantity} onChangeText={set('quantity')} keyboardType="number-pad" /></View>
          <View style={{ flex: 1 }}><Field label="Ritiro entro (ore)" value={f.pickup_hours} onChangeText={set('pickup_hours')} keyboardType="number-pad" /></View>
        </View>
        <ErrorText>{nError}</ErrorText>
        <Button title="Pubblica" onPress={publish} />
      </Panel>

      <Panel>
        <Text style={s.h2}>Prenotazioni recenti</Text>
        {d?.reservations.length ? d.reservations.map((r) => (
          <View key={r.code} style={s.item}>
            <View style={{ flex: 1 }}>
              <Text style={{ fontWeight: '700' }}>{r.code}</Text>
              <Text style={s.muted}>{r.name} · {r.qty}× {r.title}</Text>
            </View>
            <Tag done={r.status === 'collected'}>{r.status === 'collected' ? 'Ritirata' : 'In attesa'}</Tag>
          </View>
        )) : <Text style={s.muted}>Nessuna prenotazione.</Text>}
      </Panel>

      <Panel>
        <Text style={s.h2}>Le tue offerte</Text>
        {d?.offers.map((o) => (
          <View key={o.id} style={s.item}>
            <View style={{ flex: 1 }}>
              <Text style={{ fontWeight: '700' }}>{o.emoji} {o.title}</Text>
              <Text style={s.muted}>{eur(o.price_cents)} · fino alle {day(o.pickup_end)} {hhmm(o.pickup_end)}</Text>
            </View>
            <Text style={s.muted}>{o.reserved}/{o.quantity}</Text>
          </View>
        ))}
      </Panel>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  h1: { fontSize: 24, fontWeight: '800', color: colors.ink },
  h2: { fontSize: 18, fontWeight: '700', marginBottom: 10 },
  muted: { color: colors.muted },
  label: { fontWeight: '600', fontSize: 13, marginBottom: 6 },
  row: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  stats: { flexDirection: 'row', gap: 10 },
  stat: { flex: 1, marginBottom: 14 },
  statN: { fontSize: 24, fontWeight: '800', color: colors.greenDark },
  item: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.line },
});
