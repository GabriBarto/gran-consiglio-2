import { createNavigationContainerRef, NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from './src/AuthContext';
import {
  CheckEmailScreen, ForgotPasswordScreen, LoginCodeScreen, LoginScreen, RegisterScreen, ResetPasswordScreen,
} from './src/screens/AuthScreens';
import { AdminHome, ChangePasswordScreen } from './src/screens/HomeScreens';
import { BoxFormScreen } from './src/screens/BoxScreens';
import { SellerHome, SellerProfileScreen } from './src/screens/SellerScreens';
import { ShopScreen, ShopsScreen } from './src/screens/ShopScreens';
import { clearPendingBox, loadPendingBox } from './src/pendingBox';
import { colors } from './src/ui';

const Stack = createNativeStackNavigator();
// Il cliente atterra sulla ricerca dei negozi (come il visitatore); venditore e admin sulle loro schermate
const HOME_BY_ROLE = { SELLER: SellerHome, ADMIN: AdminHome };

export const navigationRef = createNavigationContainerRef();

function Routes({ navReady }) {
  const { user, booting } = useAuth();

  // Dopo il primo accesso da cliente si torna alla box che si stava guardando da visitatore (US-N04)
  useEffect(() => {
    if (!navReady || !user || user.must_change_password) return;
    let cancelled = false;
    (async () => {
      const pending = await loadPendingBox();
      if (!pending || cancelled) return;
      await clearPendingBox();
      if (user.role === 'CUSTOMER') {
        navigationRef.navigate('Shop', { shopId: pending.shopId, boxId: pending.boxId, fromIntent: true });
      }
    })();
    return () => { cancelled = true; };
  }, [navReady, user?.id, user?.must_change_password]);

  if (booting) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator size="large" color={colors.green} />
      </View>
    );
  }

  // La navigazione dipende dallo stato di accesso e dal ruolo: cambia da sola dopo login/logout.
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: colors.green, headerShadowVisible: false, contentStyle: { backgroundColor: colors.bg } }}>
      {!user ? (
        <>
          <Stack.Screen name="Shops" component={ShopsScreen} />
          <Stack.Screen name="Shop" component={ShopScreen} options={{ title: '' }} />
          <Stack.Screen name="Login" component={LoginScreen} options={{ title: '' }} />
          <Stack.Screen name="LoginCode" component={LoginCodeScreen} options={{ title: '' }} />
          <Stack.Screen name="Register" component={RegisterScreen} options={{ title: '' }} />
          <Stack.Screen name="CheckEmail" component={CheckEmailScreen} options={{ title: '', headerBackVisible: false }} />
          <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: '' }} />
          <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: '' }} />
        </>
      ) : user.must_change_password ? (
        <Stack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ headerShown: false }} />
      ) : (
        <>
          {user.role === 'CUSTOMER' ? (
            <>
              <Stack.Screen name="Shops" component={ShopsScreen} />
              <Stack.Screen name="Shop" component={ShopScreen} options={{ title: '' }} />
            </>
          ) : (
            <Stack.Screen name="Home" component={HOME_BY_ROLE[user.role]} options={{ headerShown: false }} />
          )}
          {user.role === 'SELLER' ? (
            <>
              <Stack.Screen name="SellerProfile" component={SellerProfileScreen} options={{ title: '' }} />
              <Stack.Screen name="BoxForm" component={BoxFormScreen} options={{ title: '' }} />
            </>
          ) : null}
        </>
      )}
    </Stack.Navigator>
  );
}

export default function App() {
  const [navReady, setNavReady] = useState(false);

  return (
    <SafeAreaProvider>
      <AuthProvider>
        <NavigationContainer ref={navigationRef} onReady={() => setNavReady(true)}>
          <Routes navReady={navReady} />
        </NavigationContainer>
      </AuthProvider>
      <StatusBar style="dark" />
    </SafeAreaProvider>
  );
}
