import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { colors } from '../lib/theme';

export default function RootLayout() {
  return (
    <>
      <StatusBar style="dark" />
      <Stack screenOptions={{ headerTintColor: colors.greenDark, headerStyle: { backgroundColor: '#fff' }, contentStyle: { backgroundColor: colors.cream } }}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="offerta/[id]" options={{ title: 'Prenota', presentation: 'modal' }} />
        <Stack.Screen name="prenotazione/[code]" options={{ title: 'Il tuo codice' }} />
        <Stack.Screen name="scanner" options={{ title: 'Scansiona il QR', presentation: 'modal' }} />
      </Stack>
    </>
  );
}
