import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Text, View } from 'react-native';
import { api } from '../api';
import { errorMessage } from '../errors';
import { eurosToCents, formatEuro, quote } from '../pricing';
import { useAction } from '../useAction';
import { Button, Card, Chips, colors, ErrorText, Field, Hint, LinkText, Screen, Title } from '../ui';

// Box di oggi: elenco con prezzo, guadagno e stock, e pulsante per crearne una nuova.
export function TodayBoxes({ navigation }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      setData(await api.boxes());
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);

  // Si aggiorna ogni volta che si torna a questa schermata (dopo aver creato o modificato una box)
  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <Card>
      <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 }}>Box di oggi</Text>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}
      {data?.data.length === 0 ? <Hint>Non hai ancora pubblicato box per oggi.</Hint> : null}
      {data?.data.map((box) => (
        <Pressable key={box.id} onPress={() => navigation.navigate('BoxForm', { boxId: box.id })} style={{ paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.border }}>
          <Text style={{ fontWeight: '700', color: colors.text }}>{box.box_type.label}</Text>
          <Text style={{ color: colors.muted }} numberOfLines={1}>{box.category.label} · {box.description}</Text>
          <Text style={{ color: colors.text, marginTop: 4 }}>
            {formatEuro(box.price_cents)} · disponibili {box.stock_available} su {box.stock_total}
            {box.sold_out ? ' · esaurita' : ''}
          </Text>
          <Text style={{ color: box.earning_not_positive ? colors.error : colors.green, fontWeight: '600' }}>
            Guadagno {formatEuro(box.earning_cents)} a box
          </Text>
        </Pressable>
      ))}
      <Button title="Nuova box" onPress={() => navigation.navigate('BoxForm', {})} />
    </Card>
  );
}

// Crea (senza boxId) o modifica (con boxId) una box di oggi.
export function BoxFormScreen({ route, navigation }) {
  const boxId = route.params?.boxId;
  const editing = boxId !== undefined;

  const [options, setOptions] = useState(null);
  const [box, setBox] = useState(null); // la box in modifica, com'è sul server
  const [loadError, setLoadError] = useState('');

  const [typeId, setTypeId] = useState(null);
  const [categoryId, setCategoryId] = useState(null);
  const [attributes, setAttributes] = useState([]);
  const [description, setDescription] = useState('');
  const [listText, setListText] = useState('');
  const [discount, setDiscount] = useState(65);
  const [quantityText, setQuantityText] = useState('1');
  const [confirmLow, setConfirmLow] = useState(false);
  const save = useAction();
  const remove = useAction();

  useEffect(() => {
    (async () => {
      try {
        const opts = await api.boxOptions();
        setOptions(opts);
        if (editing) {
          const b = await api.box(boxId);
          setBox(b);
          setTypeId(b.box_type.id);
          setCategoryId(b.category.id);
          setAttributes(b.attributes);
          setDescription(b.description);
          setListText((b.list_value_cents / 100).toFixed(2).replace('.', ','));
          setDiscount(b.discount_pct);
          setQuantityText(String(b.stock_total));
        } else {
          setTypeId(opts.box_types[0]?.id ?? null);
          setCategoryId(opts.categories[0]?.id ?? null);
        }
      } catch (e) {
        setLoadError(errorMessage(e));
      }
    })();
  }, [editing, boxId]);

  if (!options || (editing && !box)) {
    return (
      <Screen>
        <Title>{editing ? 'Modifica box' : 'Nuova box'}</Title>
        {loadError ? <ErrorText>{loadError}</ErrorText> : <ActivityIndicator size="large" color={colors.green} />}
      </Screen>
    );
  }

  const listCents = eurosToCents(listText);
  const quantity = /^\d{1,3}$/.test(quantityText) ? parseInt(quantityText, 10) : null;
  const priceLocked = editing && box.price_locked;
  const sold = editing ? box.sold : 0;

  // Anteprima: stessa formula del server (che ricalcola sempre)
  const preview = listCents && listCents > 0 ? quote(listCents, discount, options.fee_cents) : null;
  const notPositive = preview ? preview.earning_cents <= 0 : false;

  const valid = typeId && categoryId && description.trim().length >= 5 && preview && quantity && quantity >= 1
    && (!notPositive || confirmLow);

  const submit = () => save.run(async () => {
    const body = {
      box_type_id: typeId,
      category_id: categoryId,
      attributes,
      description: description.trim(),
      quantity,
      confirm_low_earning: confirmLow,
    };
    if (!priceLocked) {
      body.list_value_cents = listCents;
      body.discount_pct = discount;
    }
    try {
      if (editing) await api.updateBox(boxId, body);
      else await api.createBox(body);
      navigation.goBack();
    } catch (e) {
      // Il server chiede la conferma esplicita quando il guadagno è nullo o negativo: l'app la richiede già
      if (e.code === 'LOW_EARNING_CONFIRMATION_REQUIRED') setConfirmLow(false);
      throw e;
    }
  });

  const askDelete = () => Alert.alert(
    'Eliminare la box?',
    'Non potrai recuperarla.',
    [
      { text: 'Annulla', style: 'cancel' },
      { text: 'Elimina', style: 'destructive', onPress: () => remove.run(async () => { await api.deleteBox(boxId); navigation.goBack(); }) },
    ],
  );

  const attributeOptions = options.attributes.map((a) => ({ key: a.code, label: a.label }));

  return (
    <Screen>
      <Title>{editing ? 'Modifica box' : 'Nuova box per oggi'}</Title>
      {editing && sold > 0 ? <Hint>Ne hai già vendute {sold}: valore e sconto sono bloccati e la quantità può scendere solo fino a {sold}.</Hint> : null}

      <Chips label="Tipologia" options={options.box_types.map((t) => ({ key: t.id, label: t.label }))} value={typeId} onChange={setTypeId} />
      <Chips label="Categoria" options={options.categories.map((c) => ({ key: c.id, label: c.label }))} value={categoryId} onChange={setCategoryId} />
      <Chips label="Caratteristiche delle piante" options={attributeOptions} value={attributes} onChange={setAttributes} multi />

      <Field label="Contenuto (descrizione generale)" value={description} onChangeText={setDescription} multiline numberOfLines={3} autoCapitalize="sentences" placeholder="Es. Tre piante grasse miste in vaso da 10 cm" style={{ minHeight: 80, textAlignVertical: 'top' }} />

      <Field label="Valore di listino del contenuto (€)" value={listText} onChangeText={setListText} keyboardType="decimal-pad" placeholder="20,00" editable={!priceLocked} />
      <Chips
        label="Sconto"
        options={Array.from({ length: options.discount_max_pct - options.discount_min_pct + 1 }, (_, i) => {
          const pct = options.discount_min_pct + i;
          return { key: pct, label: `${pct}%` };
        })}
        value={discount}
        onChange={setDiscount}
        disabled={priceLocked}
      />

      <Field label={editing ? `Quantità (al massimo ${box.stock_total}, almeno ${sold || 1})` : 'Quantità per oggi'} value={quantityText} onChangeText={setQuantityText} keyboardType="number-pad" maxLength={3} />

      {preview ? (
        <Card style={{ borderColor: notPositive ? colors.error : colors.border }}>
          <Text style={{ fontWeight: '700', color: colors.text, marginBottom: 8 }}>Riepilogo del prezzo</Text>
          <Line label="Valore di listino" value={formatEuro(listCents)} />
          <Line label={`Sconto ${discount}%`} value={`−${formatEuro(listCents - preview.price_cents)}`} />
          <Line label="Prezzo di vendita" value={formatEuro(preview.price_cents)} bold />
          <Line label="Tariffa di servizio" value={`−${formatEuro(preview.fee_cents)}`} />
          <Line label="Il tuo guadagno" value={formatEuro(preview.earning_cents)} bold color={notPositive ? colors.error : colors.green} />
          {notPositive ? (
            <>
              <Text style={{ color: colors.error, marginTop: 8, fontWeight: '600' }}>
                Attenzione: con questo prezzo guadagni {preview.earning_cents === 0 ? 'zero' : 'meno di zero'}. Non ti verrà addebitato nulla, ma ogni box venduta ti rende {preview.earning_cents === 0 ? 'niente' : 'zero euro'}.
              </Text>
              <Pressable onPress={() => setConfirmLow(!confirmLow)} style={{ flexDirection: 'row', alignItems: 'center', marginTop: 10 }}>
                <View style={{ width: 22, height: 22, borderRadius: 5, borderWidth: 1, borderColor: colors.error, marginRight: 10, backgroundColor: confirmLow ? colors.error : '#fff', alignItems: 'center', justifyContent: 'center' }}>
                  {confirmLow ? <Text style={{ color: '#fff', fontWeight: '700' }}>✓</Text> : null}
                </View>
                <Text style={{ flex: 1, color: colors.text }}>Ho capito, pubblica comunque</Text>
              </Pressable>
            </>
          ) : null}
        </Card>
      ) : <Hint>Inserisci il valore di listino per vedere prezzo e guadagno.</Hint>}

      <ErrorText>{save.error}</ErrorText>
      <Button title={editing ? 'Salva le modifiche' : 'Pubblica la box'} onPress={submit} loading={save.loading} disabled={!valid} />
      {editing ? (
        <>
          <ErrorText>{remove.error}</ErrorText>
          <Button title="Elimina la box" secondary onPress={askDelete} loading={remove.loading} />
        </>
      ) : null}
      <LinkText onPress={() => navigation.goBack()}>Annulla</LinkText>
    </Screen>
  );
}

const Line = ({ label, value, bold, color }) => (
  <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
    <Text style={{ color: colors.muted }}>{label}</Text>
    <Text style={{ color: color ?? colors.text, fontWeight: bold ? '700' : '400' }}>{value}</Text>
  </View>
);
