import { useState } from 'react';
import { useAuth } from './auth.jsx';
import { errorMessage } from './errors.js';
import { api } from './api.js';
import Verifiche from './Verifiche.jsx';

// Esegue un'azione asincrona con stato "in corso" ed errore tradotto.
function useAction() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const run = async (fn) => {
    setLoading(true);
    setError('');
    try { await fn(); } catch (e) { setError(errorMessage(e)); } finally { setLoading(false); }
  };
  return { loading, error, run };
}

function Card({ title, hint, children }) {
  return (
    <main className="center">
      <div className="card">
        <h1>{title}</h1>
        {hint ? <p className="hint">{hint}</p> : null}
        {children}
      </div>
    </main>
  );
}

function Login({ onChallenge }) {
  const { startLogin } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const { loading, error, run } = useAction();

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      const res = await startLogin(email.trim(), password);
      onChallenge({ challengeId: res.challenge_id, email: email.trim() });
    });
  };

  return (
    <Card title="TooGood Piante – Amministrazione" hint="Accesso riservato agli amministratori.">
      <form onSubmit={submit}>
        <label>Email<input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus /></label>
        <label>Password<input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></label>
        {error ? <p className="error">{error}</p> : null}
        <button disabled={loading || !email || !password}>{loading ? 'Attendi…' : 'Accedi'}</button>
      </form>
    </Card>
  );
}

function LoginCode({ challenge, onBack }) {
  const { finishLogin } = useAuth();
  const [code, setCode] = useState('');
  const { loading, error, run } = useAction();

  // Se l'accesso riesce, il contesto imposta l'utente e la pagina cambia da sola.
  const submit = (e) => {
    e.preventDefault();
    run(() => finishLogin(challenge.challengeId, code.trim()));
  };

  return (
    <Card title="Inserisci il codice" hint={`Abbiamo inviato un codice di 6 cifre a ${challenge.email}. Vale 10 minuti.`}>
      <form onSubmit={submit}>
        <label>Codice<input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="one-time-code" autoFocus /></label>
        {error ? <p className="error">{error}</p> : null}
        <button disabled={loading || code.trim().length !== 6}>{loading ? 'Attendi…' : 'Conferma'}</button>
        <button type="button" className="link" onClick={onBack}>Torna indietro</button>
      </form>
    </Card>
  );
}

// Primo accesso dell'admin: la password va cambiata prima di poter fare altro.
function ChangePassword() {
  const { changePassword, logout } = useAuth();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const { loading, error, run } = useAction();

  const submit = (e) => {
    e.preventDefault();
    run(() => changePassword({ current_password: current, password, password_confirmation: confirm }));
  };

  return (
    <Card title="Cambia la password" hint="Per sicurezza devi scegliere una nuova password prima di continuare (almeno 10 caratteri, con lettere e numeri).">
      <form onSubmit={submit}>
        <label>Password attuale<input type="password" value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="current-password" /></label>
        <label>Nuova password<input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" /></label>
        <label>Conferma nuova password<input type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="new-password" /></label>
        {error ? <p className="error">{error}</p> : null}
        <button disabled={loading || !current || !password || !confirm}>{loading ? 'Attendi…' : 'Salva'}</button>
        <button type="button" className="link" onClick={logout}>Esci</button>
      </form>
    </Card>
  );
}

export default function App() {
  const { user, booting, logout } = useAuth();
  const [challenge, setChallenge] = useState(null);

  if (booting) return <main className="center"><p className="hint">Caricamento…</p></main>;
  if (!user) {
    return challenge
      ? <LoginCode challenge={challenge} onBack={() => setChallenge(null)} />
      : <Login onChallenge={setChallenge} />;
  }
  if (user.must_change_password) return <ChangePassword />;

  return (
    <>
      <header className="topbar">
        <strong>TooGood Piante · Amministrazione</strong>
        <span>{user.email} <button className="link" onClick={logout}>Esci</button></span>
      </header>
      <Verifiche />
    </>
  );
}
