import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { api } from '../api';
import { earningText, payoutInfo, payoutLine, summaryRows } from '../earnings';
import { errorMessage } from '../errors';
import { formatDate } from '../orders';
import { Card, colors, ErrorText, Hint, LinkText } from '../ui';

const TONE = { ok: colors.green, warn: '#9a6b00', bad: colors.error, muted: colors.muted };
const SHOWN = 5; // ordini mostrati prima di "Mostra tutti"

// Incassi del venditore (US-V16): quanto è stato trasferito, quanto aspetta e quanto è sospeso.
export function Earnings() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [all, setAll] = useState(false);

  const load = useCallback(async () => {
    try {
      setData(await api.sellerEarnings());
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const rows = data?.data ?? [];
  const shown = all ? rows : rows.slice(0, SHOWN);

  return (
    <Card>
      <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Incassi</Text>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}

      {data ? (
        <>
          {summaryRows(data.summary).map((r) => (
            <View key={r.key} style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
              <Text style={{ color: colors.text }}>{r.label}</Text>
              <Text style={{ fontWeight: '700', color: TONE[r.tone] }}>{r.value}</Text>
            </View>
          ))}
          <Hint>
            Il pagamento arriva 24 ore dopo la fine della fascia di ritiro, sia per le box ritirate sia per quelle non
            ritirate. Con un reclamo aperto resta sospeso.
          </Hint>

          {rows.length === 0 ? <Hint>Nessun ordine pagato finora.</Hint> : null}
          {shown.map((row) => {
            const info = payoutInfo(row.payout_status);

            return (
              <View key={row.order_id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 2 }}>
                  <Text style={{ fontWeight: '700', color: colors.text }}>{row.number} · {formatDate(row.order_date)}</Text>
                  <Text style={{ fontWeight: '700', color: TONE[info.tone] }}>{info.label}</Text>
                </View>
                <Text style={{ color: row.earning_cents < 0 ? colors.error : colors.muted }}>{earningText(row)}</Text>
                <Text style={{ color: colors.muted }}>{payoutLine(row)}</Text>
              </View>
            );
          })}
          {rows.length > SHOWN ? (
            <LinkText onPress={() => setAll(!all)}>{all ? 'Mostra meno' : `Mostra tutti (${rows.length})`}</LinkText>
          ) : null}
        </>
      ) : null}

      <LinkText onPress={load}>Aggiorna</LinkText>
    </Card>
  );
}
