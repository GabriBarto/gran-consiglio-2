import { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { api, ApiError, Dashboard, Session } from '../../lib/api';
import { eur, day, hhmm } from '../../lib/format';
import { takeScannedCode } from '../../lib/scan';
import { getSession, setSession, takeNext } from '../../lib/storage';
import { CATEGORIES, colors } from '../../lib/theme';
import { Button, Chip, ErrorText, Field, Panel, Tag } from '../../components/ui';

export default function Account() {
  const [session, setSessionState] = useState<Session | null>(null);
  const [ready, setReady] = useState(false);

  const update = useCallback(async (s: Session | null) => {
    await setSession(s); setSessionState(s);
    // dopo l'accesso di un cliente torna alla box che stava guardando
    if (s?.user.role === 'cliente') { const next = await takeNext(); if (next) router.push(next as never); }
  }, []);
  const logout = useCallback(async () => {
    const s = await getSession();
    await update(null);
    if (s) api('/api/auth/logout', { method: 'POST', token: s.token }).catch(() => {});
  }, [update]);

  // a ogni apertura della scheda ricontrolla la sessione sul server (scaduta? sospesa? cambio password?)
  useFocusEffect(useCallback(() => {
    (async () => {
      const s = await getSession();
      if (s) {
        try { const me = await api<{ user: Session['user']; shop: Session['shop'] }>('/api/auth/me', { token: s.token }); await update({ ...s, ...me }); }
        catch { await update(null); }
      } else setSessionState(null);
      setReady(true);
    })();
  }, [update]));

  if (!ready) return <View />;
  if (!session) return <AuthForm onDone={update} />;
  if (session.user.must_change_password) return <ChangePassword session={session} onDone={() => update({ ...session, user: { ...session.user, must_change_password: false } })} />;
  if (session.user.role === 'venditore') return <SellerDashboard session={session} onLogout={logout} />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <Text style={s.h1}>Ciao {session.user.username} 👋</Text>
      <Panel style={{ marginTop: 16 }}>
        <Text style={{ marginBottom: 12 }}>
          {session.user.role === 'admin'
            ? 'Sei collegato come amministratore. Il pannello di gestione arriva con la Fase 3.'
            : 'Sei collegato come cliente. Scegli le sorprese da Scopri o dai Negozi e pagale dal Carrello.'}
        </Text>
        <Button title="Esci" variant="secondary" onPress={logout} />
      </Panel>
    </ScrollView>
  );
}

function AuthForm({ onDone }: { onDone: (s: Session) => void }) {
  const [register, setRegister] = useState(false);
  const [role, setRole] = useState<'cliente' | 'venditore'>('cliente');
  const [f, setF] = useState({ username: '', email: '', password: '', password_confirm: '' });
  const [error, setError] = useState('');
  const set = (k: keyof typeof f) => (v: string) => setF({ ...f, [k]: v });

  async function submit() {
    try {
      const body = register ? { ...f, role } : { email: f.email, password: f.password };
      onDone(await api<Session>(register ? '/api/auth/register' : '/api/auth/login', { method: 'POST', body }));
    } catch (e) { setError((e as Error).message); }
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Text style={s.h1}>{register ? 'Crea il tuo account' : 'Accedi'}</Text>
      <Text style={s.muted}>Clienti e venditori: trasforma l'invenduto in ricavo, o salva una pianta a prezzo ridotto.</Text>
      <Panel style={{ marginTop: 16 }}>
        {register && (
          <>
            <Text style={s.label}>Sono un…</Text>
            <View style={{ flexDirection: 'row', marginBottom: 12 }}>
              <Chip label="Cliente" active={role === 'cliente'} onPress={() => setRole('cliente')} />
              <Chip label="Venditore" active={role === 'venditore'} onPress={() => setRole('venditore')} />
            </View>
            <Field label="Nome utente" value={f.username} onChangeText={set('username')} autoCapitalize="none" />
          </>
        )}
        <Field label="Email" value={f.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" autoComplete="email" />
        <Field label="Password" value={f.password} onChangeText={set('password')} secureTextEntry placeholder={register ? 'Almeno 8 caratteri, lettere e numeri' : undefined} />
        {register && <Field label="Ripeti la password" value={f.password_confirm} onChangeText={set('password_confirm')} secureTextEntry />}
        <ErrorText>{error}</ErrorText>
        <Button title={register ? 'Registrati' : 'Accedi'} onPress={submit} />
        <View style={{ height: 10 }} />
        <Button title={register ? 'Ho già un account' : 'Crea un account'} variant="secondary" onPress={() => { setRegister(!register); setError(''); }} />
        {!register && <Text style={[s.muted, { marginTop: 12, fontSize: 12 }]}>Demo: glicine@demo.it / demo1234 (venditore) · cliente@demo.it / demo1234</Text>}
      </Panel>
    </ScrollView>
  );
}

function ChangePassword({ session, onDone }: { session: Session; onDone: () => void }) {
  const [f, setF] = useState({ current_password: '', new_password: '', new_password_confirm: '' });
  const [error, setError] = useState('');
  const set = (k: keyof typeof f) => (v: string) => setF({ ...f, [k]: v });
  async function submit() {
    try { await api('/api/auth/change-password', { method: 'POST', token: session.token, body: f }); onDone(); }
    catch (e) { setError((e as Error).message); }
  }
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Text style={s.h1}>Scegli una nuova password</Text>
      <Text style={s.muted}>Per sicurezza devi cambiare la password iniziale prima di continuare.</Text>
      <Panel style={{ marginTop: 16 }}>
        <Field label="Password attuale" value={f.current_password} onChangeText={set('current_password')} secureTextEntry />
        <Field label="Nuova password" value={f.new_password} onChangeText={set('new_password')} secureTextEntry />
        <Field label="Ripeti la nuova password" value={f.new_password_confirm} onChangeText={set('new_password_confirm')} secureTextEntry />
        <ErrorText>{error}</ErrorText>
        <Button title="Cambia password" onPress={submit} />
      </Panel>
    </ScrollView>
  );
}

function SellerDashboard({ session, onLogout }: { session: Session; onLogout: () => void }) {
  const [d, setD] = useState<Dashboard | null>(null);
  const [code, setCode] = useState('');
  const [rdError, setRdError] = useState('');
  const [f, setF] = useState({ title: '', description: '', category: 'piante', original_price: '', price: '', quantity: '5', pickup_hours: '4' });
  const [nError, setNError] = useState('');
  const [blocked, setBlocked] = useState('');
  const token = session.token;

  const load = useCallback(async () => {
    try { setD(await api<Dashboard>('/api/seller/dashboard', { token })); }
    catch (e) {
      if (e instanceof ApiError && e.status === 401) onLogout();
      else if (e instanceof ApiError && e.status === 403) setBlocked(e.message);
    }
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
        <Text style={s.h1}>{d ? `${d.shop.emoji} ${d.shop.name}` : `🏪 ${session.user.username}`}</Text>
      </View>
      <Button title="Esci" variant="secondary" onPress={onLogout} style={{ alignSelf: 'flex-start', marginBottom: 12, paddingVertical: 8 }} />

      {blocked ? (
        <Panel>
          <Text style={{ fontWeight: '700', marginBottom: 6 }}>{blocked}.</Text>
          <Text style={s.muted}>La registrazione del negozio (dati, orari e partita IVA) arriva con la Fase 2.</Text>
        </Panel>
      ) : d && d.shop.license_status !== 'approved' ? (
        <Panel><Text style={s.muted}>La tua attività è in attesa di verifica: potrai pubblicare le box dopo l'approvazione.</Text></Panel>
      ) : null}

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
