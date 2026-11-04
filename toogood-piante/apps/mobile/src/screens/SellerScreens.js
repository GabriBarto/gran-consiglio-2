import * as DocumentPicker from 'expo-document-picker';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, AppState, Linking, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { errorMessage } from '../errors';
import { useAction } from '../useAction';
import { TodayBoxes } from './BoxScreens';
import { Button, colors, ErrorText, Field, Hint, LinkText, Screen, Title } from '../ui';

const STATUS_TEXT = {
  INCOMPLETE: { label: 'Da completare', color: colors.muted, hint: 'Inserisci i dati dell’attività e allega un documento per far verificare il tuo negozio.' },
  PENDING: { label: 'In verifica', color: '#9a6b00', hint: 'Stiamo controllando i tuoi dati. Riceverai un’email appena c’è l’esito.' },
  APPROVED: { label: 'Approvata', color: colors.green, hint: 'La tua attività è stata approvata.' },
  REJECTED: { label: 'Rifiutata', color: colors.error, hint: 'La richiesta non è stata approvata. Correggi e invia di nuovo.' },
  SUSPENDED: { label: 'Sospesa', color: colors.error, hint: 'Il tuo negozio è sospeso. Contatta l’assistenza.' },
};

function StatusBadge({ status }) {
  const s = STATUS_TEXT[status] ?? STATUS_TEXT.INCOMPLETE;
  return (
    <View style={{ alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 5, borderRadius: 20, borderWidth: 1, borderColor: s.color, marginBottom: 12 }}>
      <Text style={{ color: s.color, fontWeight: '700' }}>{s.label}</Text>
    </View>
  );
}

const Card = ({ children }) => (
  <View style={{ backgroundColor: '#fff', borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 16, marginBottom: 16 }}>
    {children}
  </View>
);

const Row = ({ label, value }) => (
  <View style={{ flexDirection: 'row', marginBottom: 4 }}>
    <Text style={{ width: 110, color: colors.muted }}>{label}</Text>
    <Text style={{ flex: 1, color: colors.text }}>{value}</Text>
  </View>
);

// Home del venditore: mostra a che punto è l'onboarding (dati, verifica, conto di pagamento).
export function SellerHome({ navigation }) {
  const { user, logout } = useAuth();
  const [info, setInfo] = useState(null);
  const [loadError, setLoadError] = useState('');
  const stripe = useAction();

  const load = useCallback(async () => {
    try {
      setInfo(await api.sellerStatus());
      setLoadError('');
    } catch (e) {
      setLoadError(errorMessage(e));
    }
  }, []);

  // Si aggiorna ogni volta che la schermata torna visibile (anche dopo il modulo dei dati)
  useFocusEffect(useCallback(() => { load(); }, [load]));

  // Tornando dal browser di Stripe l'app chiede se il conto è stato collegato.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        api.stripeSync().then(setInfo).catch(() => {});
      }
    });
    return () => sub.remove();
  }, []);

  const connectStripe = () => stripe.run(async () => {
    const { url } = await api.stripeOnboardingUrl();
    await Linking.openURL(url);
  });

  const checkStripe = () => stripe.run(async () => { setInfo(await api.stripeSync()); });

  if (!info) {
    return (
      <Screen>
        <Title>Il tuo negozio</Title>
        {loadError ? <><ErrorText>{loadError}</ErrorText><Button title="Riprova" onPress={load} /></> : <ActivityIndicator size="large" color={colors.green} />}
        <Button title="Esci" secondary onPress={logout} />
      </Screen>
    );
  }

  const s = info.seller;
  const state = STATUS_TEXT[info.status] ?? STATUS_TEXT.INCOMPLETE;

  return (
    <Screen>
      <Title>Il tuo negozio</Title>
      <Text style={{ color: colors.muted, marginBottom: 16 }}>{user.email}</Text>

      <Card>
        <StatusBadge status={info.status} />
        <Hint>{state.hint}</Hint>
        {info.status === 'REJECTED' && info.verification_note ? (
          <Text style={{ color: colors.error, marginBottom: 12 }}>Motivo: {info.verification_note}</Text>
        ) : null}
        {s ? (
          <>
            <Row label="Attività" value={s.business_name} />
            <Row label="Partita IVA" value={s.vat_number} />
            <Row label="Indirizzo" value={s.address} />
            <Row label="Orari" value={`${s.opens_at} – ${s.closes_at}`} />
            <Row label="Ritiro" value={`${s.pickup_from} – ${s.pickup_to}`} />
            <Row label="Documenti" value={info.documents.length ? `${info.documents.length} allegat${info.documents.length === 1 ? 'o' : 'i'}` : 'Nessuno'} />
          </>
        ) : null}
        {info.status === 'INCOMPLETE' ? (
          <Button title="Inserisci i dati dell’attività" onPress={() => navigation.navigate('SellerProfile')} />
        ) : info.editable ? (
          <Button title={info.status === 'REJECTED' ? 'Correggi dati e documenti' : 'Modifica dati e documenti'} secondary onPress={() => navigation.navigate('SellerProfile')} />
        ) : null}
      </Card>

      {info.status !== 'INCOMPLETE' ? (
        <Card>
          <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Conto di pagamento</Text>
          {info.stripe_connected ? (
            <Text style={{ color: colors.green, fontWeight: '600' }}>Collegato ✓</Text>
          ) : (
            <>
              <Hint>Serve per ricevere i pagamenti. Si completa sul sito di Stripe e poi si torna qui.</Hint>
              <ErrorText>{stripe.error}</ErrorText>
              <Button title="Collega il conto di pagamento" onPress={connectStripe} loading={stripe.loading} />
              <Button title="Ho finito: controlla" secondary onPress={checkStripe} disabled={stripe.loading} />
            </>
          )}
        </Card>
      ) : null}

      {info.can_publish ? (
        <TodayBoxes navigation={navigation} />
      ) : info.status === 'APPROVED' ? (
        <Hint>Per pubblicare box ti manca solo di collegare il conto di pagamento.</Hint>
      ) : null}

      <LinkText onPress={load}>Aggiorna stato</LinkText>
      <Button title="Esci" secondary onPress={logout} />
    </Screen>
  );
}

// Modulo dei dati dell'attività, con orari e documenti.
export function SellerProfileScreen({ navigation }) {
  const [info, setInfo] = useState(null);
  const [form, setForm] = useState({
    business_name: '', vat_number: '', phone: '', address: '',
    opens_at: '08:00', closes_at: '19:00', pickup_from: '17:00', pickup_to: '19:00',
  });
  const save = useAction();
  const doc = useAction();
  const resubmit = useAction();

  const set = (key) => (value) => setForm((f) => ({ ...f, [key]: value }));

  useEffect(() => {
    api.sellerStatus().then((data) => {
      setInfo(data);
      if (data.seller) {
        const { business_name, vat_number, phone, address, opens_at, closes_at, pickup_from, pickup_to } = data.seller;
        setForm({ business_name, vat_number, phone, address, opens_at, closes_at, pickup_from, pickup_to });
      }
    }).catch(() => {});
  }, []);

  const submit = () => save.run(async () => {
    const data = await api.saveSellerProfile({ ...form, business_name: form.business_name.trim(), address: form.address.trim() });
    setInfo(data);
  });

  const pickDocument = () => doc.run(async () => {
    const result = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png'], copyToCacheDirectory: true });
    if (result.canceled) return;
    setInfo(await api.uploadSellerDocument(result.assets[0]));
  });

  const removeDocument = (id) => doc.run(async () => { setInfo(await api.deleteSellerDocument(id)); });

  const sendAgain = () => resubmit.run(async () => {
    await api.resubmitSeller();
    navigation.goBack();
  });

  if (!info) {
    return <Screen><ActivityIndicator size="large" color={colors.green} /></Screen>;
  }

  const hasProfile = !!info.seller;
  const locked = hasProfile && !info.editable;
  const filled = Object.values(form).every((v) => String(v).trim() !== '');

  return (
    <Screen>
      <Title>Dati dell’attività</Title>
      {locked ? <Hint>I dati di un’attività approvata non si modificano da qui.</Hint> : null}

      <Field label="Ragione sociale" value={form.business_name} onChangeText={set('business_name')} autoCapitalize="words" editable={!locked} />
      <Field label="Partita IVA" value={form.vat_number} onChangeText={set('vat_number')} placeholder="IT12345678901" autoCapitalize="characters" editable={!locked} />
      <Field label="Telefono" value={form.phone} onChangeText={set('phone')} keyboardType="phone-pad" editable={!locked} />
      <Field label="Indirizzo (via, numero, città)" value={form.address} onChangeText={set('address')} placeholder="Via Rizzoli 5, Bologna" autoCapitalize="words" editable={!locked} />
      <Hint>Orari in formato 24 ore, per esempio 08:30.</Hint>
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1 }}><Field label="Apertura" value={form.opens_at} onChangeText={set('opens_at')} keyboardType="numbers-and-punctuation" maxLength={5} editable={!locked} /></View>
        <View style={{ flex: 1 }}><Field label="Chiusura" value={form.closes_at} onChangeText={set('closes_at')} keyboardType="numbers-and-punctuation" maxLength={5} editable={!locked} /></View>
      </View>
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1 }}><Field label="Ritiro da" value={form.pickup_from} onChangeText={set('pickup_from')} keyboardType="numbers-and-punctuation" maxLength={5} editable={!locked} /></View>
        <View style={{ flex: 1 }}><Field label="Ritiro fino a" value={form.pickup_to} onChangeText={set('pickup_to')} keyboardType="numbers-and-punctuation" maxLength={5} editable={!locked} /></View>
      </View>
      <ErrorText>{save.error}</ErrorText>
      {!locked ? <Button title={hasProfile ? 'Salva le modifiche' : 'Salva e continua'} onPress={submit} loading={save.loading} disabled={!filled} /> : null}

      {hasProfile ? (
        <View style={{ marginTop: 24 }}>
          <Text style={{ fontSize: 20, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Documenti</Text>
          <Hint>Allega la visura camerale o un documento dell’attività (PDF, JPG o PNG, fino a 5 MB).</Hint>
          {info.documents.map((d) => (
            <View key={d.id} style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8, backgroundColor: '#fff', borderRadius: 10, borderWidth: 1, borderColor: colors.border, padding: 12 }}>
              <Text style={{ flex: 1, color: colors.text }} numberOfLines={1}>{d.name}</Text>
              {info.editable ? <LinkText onPress={() => removeDocument(d.id)}>Elimina</LinkText> : null}
            </View>
          ))}
          <ErrorText>{doc.error}</ErrorText>
          {info.editable ? <Button title="Allega un documento" secondary onPress={pickDocument} loading={doc.loading} /> : null}
        </View>
      ) : null}

      {info.status === 'REJECTED' ? (
        <View style={{ marginTop: 16 }}>
          <ErrorText>{resubmit.error}</ErrorText>
          <Button title="Invia di nuovo per la verifica" onPress={sendAgain} loading={resubmit.loading} disabled={!info.documents.length} />
        </View>
      ) : null}

      <LinkText onPress={() => navigation.goBack()}>Torna al negozio</LinkText>
    </Screen>
  );
}
