import { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';
import { errorMessage } from './errors.js';
import { formatEuro, parseEuroToCents } from './money.js';

const TABS = [
  ['OPEN', 'Da decidere'],
  ['DECIDED', 'Decisi'],
];

const STATUS_LABEL = {
  OPEN: 'Da decidere',
  ACCEPTED_FULL: 'Rimborso totale',
  ACCEPTED_PARTIAL: 'Rimborso parziale',
  REJECTED: 'Respinto',
};
const STATUS_CLASS = { OPEN: 'pending', ACCEPTED_FULL: 'approved', ACCEPTED_PARTIAL: 'approved', REJECTED: 'rejected' };
const PAYOUT_LABEL = {
  SCHEDULED: 'Programmato',
  HELD: 'Sospeso in attesa della decisione',
  SENT: 'Già trasferito al venditore',
  SKIPPED: 'Nessun pagamento',
  REVERSED: 'Ripreso al venditore',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '–');

export default function Reclami({ onOpenCount }) {
  const [status, setStatus] = useState('OPEN');
  const [list, setList] = useState(null);
  const [error, setError] = useState('');
  const [selectedId, setSelectedId] = useState(null);

  const load = useCallback(async () => {
    setError('');
    try {
      const res = await api.complaints(status);
      setList(res.data);
      onOpenCount?.(res.open);
    } catch (e) {
      setError(errorMessage(e));
      setList([]);
    }
  }, [status, onOpenCount]);

  useEffect(() => { setList(null); setSelectedId(null); load(); }, [load]);

  return (
    <main className="layout">
      <section className="panel">
        <div className="tabs">
          {TABS.map(([key, label]) => (
            <button key={key} className={key === status ? 'tab active' : 'tab'} onClick={() => setStatus(key)}>{label}</button>
          ))}
          <button className="link" onClick={load}>Aggiorna</button>
        </div>
        {error ? <p className="error">{error}</p> : null}
        {list === null ? <p className="hint">Caricamento…</p> : null}
        {list?.length === 0 && !error ? (
          <p className="hint">{status === 'OPEN' ? 'Nessun reclamo da decidere.' : 'Nessun reclamo ancora deciso.'}</p>
        ) : null}
        <ul className="list">
          {list?.map((c) => (
            <li key={c.id}>
              <button className={c.id === selectedId ? 'row selected' : 'row'} onClick={() => setSelectedId(c.id)}>
                <strong>{c.order.number} · {c.shop.business_name}</strong>
                <span>{c.reason_label}</span>
                <span className="muted">
                  {c.customer.username} · {formatEuro(c.order.total_cents)} · {formatDate(c.created_at)}
                  {c.status !== 'OPEN' ? ` · ${STATUS_LABEL[c.status]}` : ''}
                </span>
              </button>
            </li>
          ))}
        </ul>
      </section>

      <section className="panel">
        {selectedId ? (
          <Detail key={selectedId} id={selectedId} onDecided={() => { setSelectedId(null); load(); }} />
        ) : (
          <p className="hint">Seleziona un reclamo per vedere i dettagli.</p>
        )}
      </section>
    </main>
  );
}

function Row({ label, children }) {
  return (
    <div className="kv">
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function Detail({ id, onDecided }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [decision, setDecision] = useState('');
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');

  useEffect(() => {
    api.complaint(id).then(setData).catch((e) => setError(errorMessage(e)));
  }, [id]);

  if (error && !data) return <p className="error">{error}</p>;
  if (!data) return <p className="hint">Caricamento…</p>;

  const total = data.order.total_cents;
  const open = data.status === 'OPEN';
  const refundCents = decision === 'ACCEPT_PARTIAL' ? parseEuroToCents(amount) : null;
  const amountOk = decision !== 'ACCEPT_PARTIAL' || (refundCents !== null && refundCents > 0 && refundCents < total);
  const canSubmit = decision && amountOk && note.trim().length >= 5 && !busy;

  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      await api.decide(id, {
        decision,
        note: note.trim(),
        ...(decision === 'ACCEPT_PARTIAL' ? { refund_cents: refundCents } : {}),
      });
      onDecided();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };

  const outcomeText = {
    ACCEPT_FULL: `Verranno rimborsati ${formatEuro(total)} al cliente e al venditore non andrà nulla.`,
    ACCEPT_PARTIAL: refundCents && amountOk
      ? `Verranno rimborsati ${formatEuro(refundCents)} al cliente; il guadagno del venditore scende dello stesso importo (senza andare sotto zero).`
      : 'Indica un importo maggiore di zero e minore del totale dell’ordine.',
    REJECT: 'Nessun rimborso: il pagamento al venditore riprende.',
  }[decision];

  return (
    <div>
      <h2>Ordine {data.order.number} <span className={`badge ${STATUS_CLASS[data.status]}`}>{STATUS_LABEL[data.status]}</span></h2>
      <dl>
        <Row label="Motivo">{data.reason_label}</Row>
        <Row label="Cosa scrive il cliente"><span className="quote">{data.description}</span></Row>
        <Row label="Cliente">{data.customer.username} · {data.customer.email}</Row>
        <Row label="Negozio">{data.shop.business_name}{data.shop.city ? ` (${data.shop.city})` : ''}</Row>
        <Row label="Aperto">{formatDate(data.created_at)}</Row>
        <Row label="Ritirato">{data.order.picked_up_at ? formatDate(data.order.picked_up_at) : 'No, non ritirato'}</Row>
        <Row label="Fine fascia di ritiro">{formatDate(data.order.pickup_window_end_at)}</Row>
        <Row label="Totale pagato">{formatEuro(total)}</Row>
        <Row label="Pagamento al venditore">
          {data.payout ? <>{PAYOUT_LABEL[data.payout.status] ?? data.payout.status} · {formatEuro(data.payout.amount_cents)}</> : '–'}
        </Row>
      </dl>

      <h3>Cosa ha comprato</h3>
      <ul className="docs">
        {data.order.items.map((it, i) => (
          <li key={i}>
            {it.quantity} × {it.description} <span className="muted">· pagata {formatEuro(it.unit_price_cents)}, valore minimo dichiarato {formatEuro(it.list_value_cents)}</span>
          </li>
        ))}
      </ul>

      {!open ? (
        <>
          <h3>Decisione</h3>
          <dl>
            <Row label="Esito">{STATUS_LABEL[data.status]}{data.refund_cents ? ` · ${formatEuro(data.refund_cents)}` : ''}</Row>
            <Row label="Motivazione">{data.decision_note}</Row>
            <Row label="Deciso">{formatDate(data.decided_at)}</Row>
          </dl>
        </>
      ) : (
        <>
          <h3>Decisione</h3>
          <div className="choices" role="radiogroup" aria-label="Decisione">
            {[
              ['ACCEPT_FULL', 'Rimborso totale'],
              ['ACCEPT_PARTIAL', 'Rimborso parziale'],
              ['REJECT', 'Respingi il reclamo'],
            ].map(([key, label]) => (
              <button key={key} type="button" role="radio" aria-checked={decision === key}
                className={decision === key ? 'tab active' : 'tab'} onClick={() => setDecision(key)}>{label}</button>
            ))}
          </div>

          {decision === 'ACCEPT_PARTIAL' ? (
            <label>Importo da rimborsare (€, massimo {formatEuro(total - 1)})
              <input value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" placeholder="es. 2,50" autoFocus />
            </label>
          ) : null}

          {decision ? (
            <>
              <p className={amountOk ? 'hint' : 'warn'}>{outcomeText}</p>
              {data.payout?.already_sent && decision !== 'REJECT' ? (
                <p className="warn">Il pagamento è già stato trasferito al venditore: la parte corrispondente verrà ripresa con uno storno.</p>
              ) : null}
              <label>Motivazione (la ricevono per email cliente e venditore)
                <textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
              </label>
            </>
          ) : null}

          {error ? <p className="error">{error}</p> : null}

          {decision ? (
            <div className="actions">
              <button className={decision === 'REJECT' ? 'danger' : ''} disabled={!canSubmit} onClick={submit}>
                {busy ? 'Attendi…' : decision === 'REJECT' ? 'Conferma il rifiuto' : 'Conferma il rimborso'}
              </button>
            </div>
          ) : (
            <p className="hint">Scegli una decisione. Non si può cambiare dopo la conferma.</p>
          )}
        </>
      )}
      {!open && error ? <p className="error">{error}</p> : null}
    </div>
  );
}
