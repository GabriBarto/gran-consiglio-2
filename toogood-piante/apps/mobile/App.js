import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from './src/AuthContext';
import {
  CheckEmailScreen, ForgotPasswordScreen, LoginCodeScreen, LoginScreen, RegisterScreen, ResetPasswordScreen,
} from './src/screens/AuthScreens';
import { AdminHome, ChangePasswordScreen, CustomerHome, SellerHome } from './src/screens/HomeScreens';
import { colors } from './src/ui';

const Stack = createNativeStackNavigator();
const HOME_BY_ROLE = { CUSTOMER: CustomerHome, SELLER: SellerHome, ADMIN: AdminHome };

function Routes() {
  const { user, booting } = useAuth();

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
          <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
          <Stack.Screen name="LoginCode" component={LoginCodeScreen} options={{ title: '' }} />
          <Stack.Screen name="Register" component={RegisterScreen} options={{ title: '' }} />
          <Stack.Screen name="CheckEmail" component={CheckEmailScreen} options={{ title: '', headerBackVisible: false }} />
          <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: '' }} />
          <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: '' }} />
        </>
      ) : user.must_change_password ? (
        <Stack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ headerShown: false }} />
      ) : (
        <Stack.Screen name="Home" component={HOME_BY_ROLE[user.role] ?? CustomerHome} options={{ headerShown: false }} />
      )}
    </Stack.Navigator>
  );
}

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <NavigationContainer>
          <Routes />
        </NavigationContainer>
      </AuthProvider>
      <StatusBar style="dark" />
    </SafeAreaProvider>
  );
}
