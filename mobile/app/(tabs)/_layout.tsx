import { Tabs } from 'expo-router';
import { Text } from 'react-native';
import { colors } from '../../lib/theme';

const icon = (e: string) => () => <Text style={{ fontSize: 22 }}>{e}</Text>;

export default function TabsLayout() {
  return (
    <Tabs screenOptions={{
      tabBarActiveTintColor: colors.greenDark,
      headerStyle: { backgroundColor: '#fff' }, headerTitleStyle: { color: colors.greenDark, fontWeight: '800' },
    }}>
      <Tabs.Screen name="index" options={{ title: 'Scopri', headerTitle: '🌱 VerdeSalva', tabBarIcon: icon('🔎') }} />
      <Tabs.Screen name="negozi" options={{ title: 'Negozi', tabBarIcon: icon('🏪') }} />
      <Tabs.Screen name="carrello" options={{ title: 'Carrello', tabBarIcon: icon('🛒') }} />
      <Tabs.Screen name="prenotazioni" options={{ title: 'Ordini', headerTitle: 'I miei ordini', tabBarIcon: icon('🎟️') }} />
      <Tabs.Screen name="venditori" options={{ title: 'Account', headerTitle: 'Account', tabBarIcon: icon('👤') }} />
    </Tabs>
  );
}
