import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { errorMessage } from '../errors';
import { Button, colors, ErrorText, Field, Hint, LinkText, Screen, Title } from '../ui';

// Esegue un'azione asincrona mostrando "in corso" ed eventuale errore tradotto.
function useAction() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const run = async (fn) => {
    setLoading(true);
    setError('');
    try {
      await fn();
    } catch (e) {
      setError(errorMessage(e));
      return e;
    } finally {
      setLoading(false);
    }
  };
  return { loading, error, setError, run };
}

export function LoginScreen({ navigation }) {
  const { startLogin } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const { loading, error, run } = useAction();

  const submit = () => run(async () => {
    const res = await startLogin(email.trim(), password);
    navigation.navigate('LoginCode', { challengeId: res.challenge_id, email: email.trim() });
  });

  return (
    <Screen>
      <Title>TooGood Piante</Title>
      <Hint>Accedi per salvare le piante invendute vicino a te.</Hint>
      <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoComplete="email" />
      <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" />
      <ErrorText>{error}</ErrorText>
      <Button title="Accedi" onPress={submit} loading={loading} disabled={!email || !password} />
      <LinkText onPress={() => navigation.navigate('ForgotPassword')}>Password dimenticata?</LinkText>
      <LinkText onPress={() => navigation.navigate('Register')}>Non hai un account? Registrati</LinkText>
    </Screen>
  );
}

export function LoginCodeScreen({ route, navigation }) {
  const { finishLogin } = useAuth();
  const { challengeId, email } = route.params;
  const [code, setCode] = useState('');
  const { loading, error, run } = useAction();

  // Se l'accesso riesce, il contesto imposta l'utente e la navigazione cambia da sola.
  const submit = () => run(() => finishLogin(challengeId, code.trim()));

  return (
    <Screen>
      <Title>Inserisci il codice</Title>
      <Hint>Abbiamo inviato un codice di 6 cifre a {email}. Vale 10 minuti.</Hint>
      <Field label="Codice" value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={6} autoComplete="one-time-code" />
      <ErrorText>{error}</ErrorText>
      <Button title="Conferma" onPress={submit} loading={loading} disabled={code.trim().length !== 6} />
      <LinkText onPress={() => navigation.goBack()}>Torna indietro</LinkText>
    </Screen>
  );
}

function RoleChoice({ value, onChange }) {
  const options = [['customer', 'Cliente'], ['seller', 'Venditore']];
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={{ fontSize: 14, fontWeight: '600', color: colors.text, marginBottom: 6 }}>Sono un</Text>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        {options.map(([key, label]) => (
          <Pressable
            key={key}
            onPress={() => onChange(key)}
            style={{
              flex: 1, paddingVertical: 12, borderRadius: 10, alignItems: 'center', borderWidth: 1,
              borderColor: value === key ? colors.green : colors.border,
              backgroundColor: value === key ? colors.green : '#fff',
            }}
          >
            <Text style={{ fontWeight: '600', color: value === key ? '#fff' : colors.text }}>{label}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

export function RegisterScreen({ navigation }) {
  const [role, setRole] = useState('customer');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [terms, setTerms] = useState(false);
  const { loading, error, run } = useAction();

  const submit = () => run(async () => {
    await api.register({
      role, username: username.trim(), email: email.trim(),
      password, password_confirmation: confirm, accept_terms: terms,
    });
    navigation.replace('CheckEmail', { email: email.trim() });
  });

  return (
    <Screen>
      <Title>Crea un account</Title>
      <RoleChoice value={role} onChange={setRole} />
      <Field label={role === 'seller' ? 'Nome del vivaio o negozio' : 'Nome utente'} value={username} onChangeText={setUsername} autoCapitalize="words" />
      <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoComplete="email" />
      <Field label="Password (min. 10 caratteri, lettere e numeri)" value={password} onChangeText={setPassword} secureTextEntry />
      <Field label="Conferma password" value={confirm} onChangeText={setConfirm} secureTextEntry />
      <Pressable onPress={() => setTerms(!terms)} style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 14 }}>
        <View style={{
          width: 22, height: 22, borderRadius: 5, borderWidth: 1, borderColor: colors.green, marginRight: 10,
          backgroundColor: terms ? colors.green : '#fff', alignItems: 'center', justifyContent: 'center',
        }}>
          {terms ? <Text style={{ color: '#fff', fontWeight: '700' }}>✓</Text> : null}
        </View>
        <Text style={{ flex: 1, color: colors.text }}>Accetto i termini di servizio e l’informativa sulla privacy</Text>
      </Pressable>
      {role === 'seller' ? <Hint>I dati dell’attività e i documenti si completano dopo l’accesso.</Hint> : null}
      <ErrorText>{error}</ErrorText>
      <Button title="Registrati" onPress={submit} loading={loading} disabled={!username || !email || !password || !confirm || !terms} />
      <LinkText onPress={() => navigation.goBack()}>Hai già un account? Accedi</LinkText>
    </Screen>
  );
}

export function CheckEmailScreen({ route, navigation }) {
  const { email } = route.params;
  const [sent, setSent] = useState(false);
  const { loading, error, run } = useAction();

  return (
    <Screen>
      <Title>Controlla la posta</Title>
      <Hint>Ti abbiamo inviato un link per confermare {email}. Il link vale 24 ore. Dopo la conferma potrai accedere.</Hint>
      <ErrorText>{error}</ErrorText>
      {sent ? <Hint>Email inviata di nuovo.</Hint> : null}
      <Button title="Invia di nuovo la conferma" secondary loading={loading} onPress={() => run(async () => { await api.resendVerification(email); setSent(true); })} />
      <Button title="Vai all’accesso" onPress={() => navigation.popToTop()} />
    </Screen>
  );
}

export function ForgotPasswordScreen({ navigation }) {
  const [email, setEmail] = useState('');
  const { loading, error, run } = useAction();

  const submit = () => run(async () => {
    await api.forgotPassword(email.trim());
    navigation.replace('ResetPassword', { email: email.trim() });
  });

  return (
    <Screen>
      <Title>Recupera la password</Title>
      <Hint>Inserisci la tua email: ti invieremo un codice di 6 cifre valido 30 minuti.</Hint>
      <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoComplete="email" />
      <ErrorText>{error}</ErrorText>
      <Button title="Invia il codice" onPress={submit} loading={loading} disabled={!email} />
    </Screen>
  );
}

export function ResetPasswordScreen({ route, navigation }) {
  const { email } = route.params;
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const { loading, error, run } = useAction();

  const submit = () => run(async () => {
    await api.resetPassword({ email, code: code.trim(), password, password_confirmation: confirm });
    navigation.popToTop();
  });

  return (
    <Screen>
      <Title>Nuova password</Title>
      <Hint>Se {email} ha un account, riceverai un codice. Inseriscilo qui con la nuova password.</Hint>
      <Field label="Codice" value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={6} />
      <Field label="Nuova password" value={password} onChangeText={setPassword} secureTextEntry />
      <Field label="Conferma password" value={confirm} onChangeText={setConfirm} secureTextEntry />
      <ErrorText>{error}</ErrorText>
      <Button title="Cambia password" onPress={submit} loading={loading} disabled={code.trim().length !== 6 || !password || !confirm} />
    </Screen>
  );
}
