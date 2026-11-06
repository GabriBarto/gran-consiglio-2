import { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';
import { errorMessage } from './errors.js';

const TABS = [
  ['PENDING', 'In attesa'],
  ['APPROVED', 'Approvate'],
  ['REJECTED', 'Rifiutate'],
  ['SUSPENDED', 'Sospese'],
];

const STATUS_LABEL = { PENDING: 'In attesa', APPROVED: 'Approvata', REJECTED: 'Rifiutata', SUSPENDED: 'Sospesa' };
const VAT_LABEL = {
  VALID: 'Partita IVA verificata',
  UNAVAILABLE: 'Servizio P.IVA non disponibile: controllo da fare a mano',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '–');
const formatSize = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

export default function Verifiche() {
  const [status, setStatus] = useState('PENDING');
  const [list, setList] = useState(null);
  const [error, setError] = useState('');
  const [selectedId, setSelectedId] = useState(null);

  const load = useCallback(async () => {
    setError('');
    try {
      setList((await api.sellers(status)).data);
    } catch (e) {
      setError(errorMessage(e));
      setList([]);
    }
  }, [status]);

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
        {list?.length === 0 && !error ? <p className="hint">Nessuna richiesta {STATUS_LABEL[status].toLowerCase()}.</p> : null}
        <ul className="list">
          {list?.map((s) => (
            <li key={s.id}>
              <button className={s.id === selectedId ? 'row selected' : 'row'} onClick={() => setSelectedId(s.id)}>
                <strong>{s.seller.business_name}</strong>
                <span>{s.seller.city ?? s.seller.address} · {s.email}</span>
                <span className="muted">
                  Inviata {formatDate(s.submitted_at)} · {s.documents.length} doc.
                  {s.vat_check_status === 'UNAVAILABLE' ? ' · P.IVA da controllare' : ''}
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
          <p className="hint">Seleziona una richiesta per vedere i dettagli.</p>
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
  const [rejecting, setRejecting] = useState(false);
  const [suspending, setSuspending] = useState(false);
  const [note, setNote] = useState('');

  useEffect(() => {
    api.seller(id).then(setData).catch((e) => setError(errorMessage(e)));
  }, [id]);

  if (error && !data) return <p className="error">{error}</p>;
  if (!data) return <p className="hint">Caricamento…</p>;

  const s = data.seller;
  const pending = data.status === 'PENDING';

  const openDocument = async (doc) => {
    setError('');
    try {
      const blob = await api.sellerDocument(id, doc.id);
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  const decide = async (fn) => {
    setBusy(true);
    setError('');
    try {
      await fn();
      onDecided();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };

  return (
    <div>
      <h2>{s.business_name} <span className={`badge ${data.status.toLowerCase()}`}>{STATUS_LABEL[data.status]}</span></h2>
      <dl>
        <Row label="Referente">{data.username} · {data.email}</Row>
        <Row label="Partita IVA">{s.vat_number} <span className={data.vat_check_status === 'VALID' ? 'ok' : 'warn'}>{VAT_LABEL[data.vat_check_status] ?? data.vat_check_status}</span></Row>
        <Row label="Telefono">{s.phone}</Row>
        <Row label="Indirizzo">{s.address}{s.city ? ` (${s.city})` : ''}<br /><span className="muted">{s.latitude}, {s.longitude}</span></Row>
        <Row label="Apertura">{s.opens_at}–{s.closes_at}</Row>
        <Row label="Fascia di ritiro">{s.pickup_from}–{s.pickup_to}</Row>
        <Row label="Conto di pagamento">{data.stripe_connected ? 'Collegato' : 'Non ancora collegato'}</Row>
        <Row label="Inviata">{formatDate(data.submitted_at)}</Row>
        {data.verification_note && data.status === 'REJECTED' ? <Row label="Motivo del rifiuto">{data.verification_note}</Row> : null}
      </dl>

      <h3>Documenti</h3>
      {data.documents.length === 0 ? <p className="warn">Nessun documento allegato: non si può approvare.</p> : (
        <ul className="docs">
          {data.documents.map((d) => (
            <li key={d.id}>
              <button className="link" onClick={() => openDocument(d)}>{d.name}</button>
              <span className="muted"> {d.mime_type} · {formatSize(d.size_bytes)}</span>
            </li>
          ))}
        </ul>
      )}

      {error ? <p className="error">{error}</p> : null}

      {pending ? (
        rejecting ? (
          <div className="actions">
            <label>Motivo del rifiuto (lo riceve il venditore per email)
              <textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} autoFocus />
            </label>
            <button className="danger" disabled={busy || note.trim().length < 5} onClick={() => decide(() => api.reject(id, note.trim()))}>Conferma il rifiuto</button>
            <button className="link" disabled={busy} onClick={() => setRejecting(false)}>Annulla</button>
          </div>
        ) : (
          <div className="actions">
            <button disabled={busy || data.documents.length === 0} onClick={() => decide(() => api.approve(id))}>Approva</button>
            <button className="danger-outline" disabled={busy} onClick={() => setRejecting(true)}>Rifiuta…</button>
          </div>
        )
      ) : data.status === 'APPROVED' ? (
        suspending ? (
          <div className="actions">
            <label>Motivo della sospensione (lo riceve il venditore per email)
              <textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} autoFocus />
            </label>
            <p className="hint">Da sospeso non può pubblicare box né ricevere nuovi ordini; gli ordini già pagati si ritirano comunque.</p>
            <button className="danger" disabled={busy || note.trim().length < 5} onClick={() => decide(() => api.suspend(id, note.trim()))}>Conferma la sospensione</button>
            <button className="link" disabled={busy} onClick={() => setSuspending(false)}>Annulla</button>
          </div>
        ) : (
          <div className="actions">
            <button className="danger-outline" disabled={busy} onClick={() => setSuspending(true)}>Sospendi…</button>
          </div>
        )
      ) : data.status === 'SUSPENDED' ? (
        <div className="actions">
          {data.verification_note ? <p className="warn">Motivo: {data.verification_note}</p> : null}
          <button disabled={busy} onClick={() => decide(() => api.reactivate(id))}>Riattiva</button>
        </div>
      ) : (
        <p className="hint">Questa richiesta è già stata decisa.</p>
      )}
    </div>
  );
}
