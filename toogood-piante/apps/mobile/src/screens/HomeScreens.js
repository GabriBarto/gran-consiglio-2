import { useState } from 'react';
import { Text } from 'react-native';
import { useAuth } from '../AuthContext';
import { errorMessage } from '../errors';
import { Button, ErrorText, Field, Hint, Screen, Title } from '../ui';

// Segnaposto per ruolo: le funzioni vere arrivano nei prossimi giorni.
function RoleHome({ title, hint }) {
  const { user, logout } = useAuth();
  return (
    <Screen>
      <Title>{title}</Title>
      <Hint>Ciao {user.username}! {hint}</Hint>
      <Text style={{ marginBottom: 20, color: '#667a6d' }}>{user.email}</Text>
      <Button title="Esci" secondary onPress={logout} />
    </Screen>
  );
}

export const CustomerHome = () => (
  <RoleHome title="Box vicino a te" hint="Qui vedrai le box di piante in vendita (giorno 7)." />
);
export const SellerHome = () => (
  <RoleHome title="Il tuo negozio" hint="Qui completerai i dati dell’attività e pubblicherai le box (giorni 4–6)." />
);
export const AdminHome = () => (
  <RoleHome title="Amministrazione" hint="Il pannello admin è sul web; da qui si accede solo per verificare l’account." />
);

// Primo accesso dell'admin: la password va cambiata prima di poter fare altro.
export function ChangePasswordScreen() {
  const { changePassword, logout } = useAuth();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const submit = async () => {
    setLoading(true);
    setError('');
    try {
      await changePassword({ current_password: current, password, password_confirmation: confirm });
    } catch (e) {
      setError(errorMessage(e));
      setLoading(false);
    }
  };

  return (
    <Screen>
      <Title>Cambia la password</Title>
      <Hint>Per sicurezza devi scegliere una nuova password prima di continuare.</Hint>
      <Field label="Password attuale" value={current} onChangeText={setCurrent} secureTextEntry />
      <Field label="Nuova password" value={password} onChangeText={setPassword} secureTextEntry />
      <Field label="Conferma nuova password" value={confirm} onChangeText={setConfirm} secureTextEntry />
      <ErrorText>{error}</ErrorText>
      <Button title="Salva" onPress={submit} loading={loading} disabled={!current || !password || !confirm} />
      <Button title="Esci" secondary onPress={logout} />
    </Screen>
  );
}
