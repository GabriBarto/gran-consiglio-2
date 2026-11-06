import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { api } from '../api';
import { errorMessage } from '../errors';
import {
  COMMENT_MAX, COMPLAINT_MAX, complaintDeadlineText, complaintError, complaintOutcomeText, complaintStatusInfo, RATING_LABELS,
  reviewError, reviewSummaryText, sellerComplaintLine, stars,
} from '../feedback';
import { formatDate } from '../orders';
import { formatEuro } from '../pricing';
import { useAction } from '../useAction';
import { Button, Card, Chips, colors, ErrorText, Field, Hint, LinkText } from '../ui';

const TONE = { ok: colors.green, warn: '#9a6b00', bad: colors.error, muted: colors.muted };

function ToneBadge({ label, tone }) {
  return (
    <View style={{ alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 20, borderWidth: 1, borderColor: TONE[tone] }}>
      <Text style={{ color: TONE[tone], fontWeight: '700', fontSize: 13 }}>{label}</Text>
    </View>
  );
}

const heading = { fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 6 };

// ---------------------------------------------------------------------------------------------
// Cliente: recensione dopo il ritiro (US-C12), una per ordine.
// ---------------------------------------------------------------------------------------------
export function ReviewCard({ order, onDone }) {
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState('');
  const send = useAction();

  if (order.review) {
    return (
      <Card>
        <Text style={heading}>La tua recensione</Text>
        <Text style={{ fontSize: 22, color: '#d99a00' }}>{stars(order.review.rating)}</Text>
        {order.review.comment ? <Text style={{ color: colors.text, marginTop: 4 }}>{order.review.comment}</Text> : null}
      </Card>
    );
  }
  if (!order.can_review) return null;

  const submit = () => {
    const problem = reviewError({ rating, comment });
    if (problem) return send.setError(problem);

    return send.run(async () => {
      await api.reviewOrder(order.id, { rating, ...(comment.trim() ? { comment: comment.trim() } : {}) });
      await onDone();
    });
  };

  return (
    <Card>
      <Text style={heading}>Com’è andata?</Text>
      <Hint>Lascia una recensione a {order.shop.business_name}: aiuta gli altri clienti a scegliere.</Hint>
      <View style={{ flexDirection: 'row', gap: 6, marginBottom: 6 }}>
        {[1, 2, 3, 4, 5].map((n) => (
          <Pressable key={n} onPress={() => setRating(n)} accessibilityRole="button" accessibilityLabel={`${n} stelle`} hitSlop={6}>
            <Text style={{ fontSize: 34, color: n <= rating ? '#d99a00' : colors.border }}>★</Text>
          </Pressable>
        ))}
      </View>
      <Text style={{ color: colors.muted, marginBottom: 12, minHeight: 20 }}>{RATING_LABELS[rating]}</Text>
      <Field
        label="Commento (facoltativo)"
        value={comment}
        onChangeText={setComment}
        multiline
        maxLength={COMMENT_MAX}
        autoCapitalize="sentences"
        autoCorrect
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />
      <ErrorText>{send.error}</ErrorText>
      <Button title="Invia la recensione" onPress={submit} loading={send.loading} disabled={rating === 0} />
    </Card>
  );
}

// ---------------------------------------------------------------------------------------------
// Cliente: reclamo (US-C14) nei casi ammessi e, dopo, il suo esito.
// ---------------------------------------------------------------------------------------------
export function ComplaintCard({ order, onDone }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [description, setDescription] = useState('');
  const send = useAction();
  const complaint = order.complaint;
  const options = order.complaint_options;

  if (complaint) {
    const info = complaintStatusInfo(complaint.status);

    return (
      <Card style={{ borderColor: TONE[info.tone] }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <Text style={{ ...heading, marginBottom: 0 }}>Il tuo reclamo</Text>
          <ToneBadge label={info.label} tone={info.tone} />
        </View>
        <Text style={{ color: colors.text, fontWeight: '600' }}>{complaint.reason_label}</Text>
        <Text style={{ color: colors.muted, marginBottom: 8 }}>{complaint.description}</Text>
        <Text style={{ color: colors.text }}>{complaintOutcomeText(complaint)}</Text>
        {complaint.decision_note ? <Text style={{ color: colors.muted, marginTop: 6 }}>Motivazione: {complaint.decision_note}</Text> : null}
      </Card>
    );
  }
  if (!options?.reasons?.length) return null;

  if (!open) {
    return (
      <Card>
        <Text style={heading}>C’è un problema con l’ordine?</Text>
        <Hint>{complaintDeadlineText(options)}</Hint>
        <Button title="Apri un reclamo" secondary onPress={() => setOpen(true)} />
      </Card>
    );
  }

  const allowed = options.reasons.map((r) => r.code);
  const submit = () => {
    const problem = complaintError({ reason, description }, allowed);
    if (problem) return send.setError(problem);

    return send.run(async () => {
      await api.complainAboutOrder(order.id, { reason, description: description.trim() });
      await onDone();
    });
  };

  return (
    <Card>
      <Text style={heading}>Apri un reclamo</Text>
      <Hint>
        {complaintDeadlineText(options)} Il reclamo si decide entro pochi giorni e riceverai l’esito per email. Non si può
        contestare un ordine non ritirato né una box «non gradita» che rispetta il valore dichiarato.
      </Hint>
      <Chips label="Cosa è successo?" options={options.reasons.map((r) => ({ key: r.code, label: r.label }))} value={reason} onChange={setReason} />
      <Field
        label="Descrivi il problema"
        value={description}
        onChangeText={setDescription}
        multiline
        maxLength={COMPLAINT_MAX}
        autoCapitalize="sentences"
        autoCorrect
        style={{ minHeight: 110, textAlignVertical: 'top' }}
      />
      <ErrorText>{send.error}</ErrorText>
      <Button title="Invia il reclamo" onPress={submit} loading={send.loading} />
      <LinkText onPress={() => setOpen(false)}>Annulla</LinkText>
    </Card>
  );
}

// ---------------------------------------------------------------------------------------------
// Venditore: recensioni ricevute (US-V17) e reclami sui suoi ordini (US-V18).
// ---------------------------------------------------------------------------------------------
const SHOWN = 5;

export function SellerFeedback() {
  return (
    <>
      <SellerReviews />
      <SellerComplaints />
    </>
  );
}

function useLoaded(fetcher) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    try {
      setData(await fetcher());
      setError('');
    } catch (e) {
      setError(errorMessage(e));
    }
  }, [fetcher]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  return { data, error, load };
}

const fetchReviews = () => api.sellerReviews();
const fetchComplaints = () => api.sellerComplaints();

function SellerReviews() {
  const { data, error, load } = useLoaded(fetchReviews);
  const [all, setAll] = useState(false);
  const rows = data?.data ?? [];
  const shown = all ? rows : rows.slice(0, SHOWN);

  return (
    <Card>
      <Text style={heading}>Recensioni</Text>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}
      {data ? <Text style={{ color: colors.text, marginBottom: 6 }}>{reviewSummaryText(data.summary)}</Text> : null}
      {shown.map((r) => (
        <View key={r.id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
            <Text style={{ color: '#d99a00', fontSize: 16 }}>{stars(r.rating)}</Text>
            <Text style={{ color: colors.muted }}>{r.customer.username} · {formatDate(r.created_at)}</Text>
          </View>
          {r.comment ? <Text style={{ color: colors.text, marginTop: 2 }}>{r.comment}</Text> : null}
        </View>
      ))}
      {rows.length > SHOWN ? <LinkText onPress={() => setAll(!all)}>{all ? 'Mostra meno' : `Mostra tutte (${rows.length})`}</LinkText> : null}
      <LinkText onPress={load}>Aggiorna</LinkText>
    </Card>
  );
}

function SellerComplaints() {
  const { data, error, load } = useLoaded(fetchComplaints);
  const rows = data?.data ?? [];

  return (
    <Card style={data?.open ? { borderColor: '#9a6b00' } : undefined}>
      <Text style={heading}>Reclami</Text>
      <ErrorText>{error}</ErrorText>
      {!data && !error ? <ActivityIndicator color={colors.green} /> : null}
      {data ? (
        <Text style={{ color: data.open ? '#9a6b00' : colors.text, fontWeight: data.open ? '700' : '400', marginBottom: 6 }}>
          {data.open === 0 ? 'Nessun reclamo da decidere.' : data.open === 1 ? '1 reclamo in esame.' : `${data.open} reclami in esame.`}
        </Text>
      ) : null}
      {rows.slice(0, SHOWN).map((c) => {
        const info = complaintStatusInfo(c.status);

        return (
          <View key={c.id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }}>
              <Text style={{ fontWeight: '700', color: colors.text }}>{c.order.number} · {c.customer.username}</Text>
              <ToneBadge label={info.label} tone={info.tone} />
            </View>
            <Text style={{ color: colors.text }}>{c.reason_label}</Text>
            <Text style={{ color: colors.muted }}>«{c.description}»</Text>
            <Text style={{ color: colors.muted, marginTop: 2 }}>{sellerComplaintLine(c)}</Text>
            {c.status !== 'OPEN' && c.decision_note ? <Text style={{ color: colors.muted }}>Motivazione: {c.decision_note}</Text> : null}
          </View>
        );
      })}
      {rows.length > SHOWN ? <Hint>Ci sono altri {rows.length - SHOWN} reclami meno recenti.</Hint> : null}
      <Hint>La decisione spetta alla piattaforma. Con un reclamo aperto il pagamento dell’ordine resta sospeso; un rimborso parziale lo riduce dello stesso importo.</Hint>
      <LinkText onPress={load}>Aggiorna</LinkText>
    </Card>
  );
}
