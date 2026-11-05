import { useFocusEffect } from '@react-navigation/native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Text, View } from 'react-native';
import { api } from '../api';
import { errorMessage } from '../errors';
import { localTimeOf, scanErrorText } from '../orders';
import { Button, Card, colors, ErrorText, Field, Hint, LinkText, Screen, Title } from '../ui';
import { StatusBadge } from './OrderScreens';

// ---------------------------------------------------------------------------------------------
// Ritiri di oggi (US-V14): gli ordini del negozio con lo stato, e il pulsante per scansionare.
// ---------------------------------------------------------------------------------------------
export function TodayPickups({ navigation }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      setData(await api.sellerOrders());
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);

  // Si aggiorna ogni volta che si torna alla home (dopo una scansione, per esempio)
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const s = data?.summary;

  return (
    <Card>
      <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Ritiri di oggi</Text>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}

      {s ? (
        <Text style={{ color: colors.text, marginBottom: 8 }}>
          Da ritirare: <Text style={{ fontWeight: '700' }}>{s.to_pick_up}</Text>
          {s.units_to_pick_up > 0 ? ` (${s.units_to_pick_up} box)` : ''}
          {' · '}Ritirati: <Text style={{ fontWeight: '700' }}>{s.picked_up}</Text>
          {' · '}Non ritirati: <Text style={{ fontWeight: '700' }}>{s.not_picked_up}</Text>
        </Text>
      ) : null}

      {data?.data.length === 0 ? <Hint>Nessun ordine per oggi.</Hint> : null}
      {data?.data.map((order) => (
        <View key={order.id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }}>
            <Text style={{ fontWeight: '700', color: colors.text }}>{order.number} · {order.customer.username}</Text>
            <StatusBadge status={order.status} />
          </View>
          {order.items.map((item) => (
            <Text key={item.description} style={{ color: colors.muted }}>{item.quantity} × {item.description}</Text>
          ))}
          {order.picked_up_at ? <Text style={{ color: colors.green }}>Ritirato alle {localTimeOf(order.picked_up_at)}</Text> : null}
        </View>
      ))}

      <Button title="Scansiona il QR di un cliente" onPress={() => navigation.navigate('Scan')} />
      <LinkText onPress={load}>Aggiorna</LinkText>
    </Card>
  );
}

// ---------------------------------------------------------------------------------------------
// Scansione del QR con la fotocamera, o inserimento a mano del codice (US-V15).
// ---------------------------------------------------------------------------------------------
export function ScanScreen({ navigation }) {
  const [permission, requestPermission] = useCameraPermissions();
  const [result, setResult] = useState(null); // { ok: true, order } oppure { ok: false, text }
  const [code, setCode] = useState('');
  const [sending, setSending] = useState(false);
  // La fotocamera può riconoscere lo stesso QR decine di volte al secondo: si manda una sola richiesta alla volta
  const busy = useRef(false);

  const send = async (body) => {
    if (busy.current) return;
    busy.current = true;
    setSending(true);
    try {
      const res = await api.scanPickup(body);
      setResult({ ok: true, order: res.order });
    } catch (e) {
      setResult({ ok: false, text: scanErrorText(e) });
    } finally {
      setSending(false);
    }
  };

  const reset = () => {
    busy.current = false;
    setResult(null);
    setCode('');
  };

  const typedCode = code.replace(/[^A-Za-z0-9]/g, '');

  // ----- Esito -----
  if (result) {
    return (
      <Screen>
        {result.ok ? (
          <Card style={{ borderColor: colors.green, borderWidth: 2 }}>
            <Text style={{ color: colors.green, fontSize: 20, fontWeight: '700', marginBottom: 6 }}>Ritiro confermato ✓</Text>
            <Text style={{ fontWeight: '700', color: colors.text }}>{result.order.number}</Text>
            <Text style={{ color: colors.text }}>Cliente: {result.order.customer.username}</Text>
            {result.order.items.map((item) => (
              <Text key={item.description} style={{ color: colors.text }}>{item.quantity} × {item.description}</Text>
            ))}
            <Text style={{ color: colors.muted, marginTop: 6 }}>Ritirato alle {localTimeOf(result.order.picked_up_at)}</Text>
          </Card>
        ) : (
          <Card style={{ borderColor: colors.error, borderWidth: 2 }}>
            <Text style={{ color: colors.error, fontSize: 18, fontWeight: '700', marginBottom: 6 }}>Ritiro non confermato</Text>
            <Text style={{ color: colors.text }}>{result.text}</Text>
          </Card>
        )}
        <Button title="Scansiona un altro ordine" onPress={reset} />
        <LinkText onPress={() => navigation.goBack()}>Torna ai ritiri di oggi</LinkText>
      </Screen>
    );
  }

  // ----- Scansione -----
  return (
    <Screen>
      <Title>Scansiona il QR</Title>

      {!permission ? <ActivityIndicator color={colors.green} /> : null}

      {permission && !permission.granted ? (
        <View style={{ marginBottom: 12 }}>
          <Hint>Per leggere il QR serve il permesso di usare la fotocamera. Se preferisci, puoi scrivere il codice a mano qui sotto.</Hint>
          {permission.canAskAgain
            ? <Button title="Consenti l’uso della fotocamera" onPress={requestPermission} />
            : <Button title="Apri le impostazioni" secondary onPress={() => Linking.openSettings()} />}
        </View>
      ) : null}

      {permission?.granted ? (
        <View style={{ height: 320, borderRadius: 12, overflow: 'hidden', marginBottom: 12, backgroundColor: '#000' }}>
          <CameraView
            style={{ flex: 1 }}
            facing="back"
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={({ data }) => send({ qr: data })}
          />
        </View>
      ) : null}
      {permission?.granted ? <Hint>Inquadra il QR che il cliente mostra nell’app.</Hint> : null}
      {sending ? <ActivityIndicator color={colors.green} /> : null}

      <Field
        label="Oppure scrivi il codice di ritiro"
        value={code}
        onChangeText={setCode}
        autoCapitalize="characters"
        maxLength={12}
        placeholder="ABCD-EFGH"
      />
      <Button title="Conferma il ritiro" onPress={() => send({ code })} loading={sending} disabled={typedCode.length < 8} />
      <LinkText onPress={() => navigation.goBack()}>Annulla</LinkText>
    </Screen>
  );
}
