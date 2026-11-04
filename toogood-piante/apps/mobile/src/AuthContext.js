import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Platform } from 'react-native';
import { api, bindSession } from './api';
import { clearRefreshToken, loadDeviceToken, loadRefreshToken, saveDeviceToken, saveRefreshToken } from './storage';

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

const DEVICE = `${Platform.OS}-app`;

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);
  // L'access token vive solo in memoria; il refresh token è nel deposito sicuro del telefono.
  const accessToken = useRef(null);
  const refreshToken = useRef(null);
  const refreshing = useRef(null);

  const storeTokens = useCallback(async (data) => {
    accessToken.current = data.access_token;
    refreshToken.current = data.refresh_token;
    await saveRefreshToken(data.refresh_token);
  }, []);

  const dropSession = useCallback(async () => {
    accessToken.current = null;
    refreshToken.current = null;
    await clearRefreshToken();
    setUser(null);
  }, []);

  // Una sola richiesta di refresh alla volta: il refresh token è monouso.
  const refresh = useCallback(() => {
    if (!refreshToken.current) return Promise.resolve(false);
    if (!refreshing.current) {
      refreshing.current = api.refresh(refreshToken.current)
        .then(async (data) => { await storeTokens(data); return true; })
        .catch(async () => { await dropSession(); return false; })
        .finally(() => { refreshing.current = null; });
    }
    return refreshing.current;
  }, [storeTokens, dropSession]);

  useEffect(() => {
    bindSession({ getAccessToken: () => accessToken.current, refresh });
  }, [refresh]);

  useEffect(() => {
    (async () => {
      refreshToken.current = await loadRefreshToken();
      if (refreshToken.current && (await refresh())) {
        try { setUser(await api.me()); } catch { await dropSession(); }
      }
      setBooting(false);
    })();
  }, [refresh, dropSession]);

  const value = useMemo(() => ({
    user,
    booting,
    // Passo 1. Su un dispositivo già verificato entra subito e restituisce null;
    // altrimenti restituisce la risposta con il challenge_id (il codice arriva per email).
    startLogin: async (email, password) => {
      const res = await api.login(email, password, await loadDeviceToken(email), DEVICE);
      if (res.requires_code === false) {
        await storeTokens(res);
        setUser(res.user);
        return null;
      }
      return res;
    },
    // Passo 2: scambia il codice con i token ed entra; il dispositivo viene ricordato.
    finishLogin: async (challengeId, code) => {
      const data = await api.verifyLogin(challengeId, code, DEVICE);
      if (data.device_token) await saveDeviceToken(data.user.email, data.device_token).catch(() => {});
      await storeTokens(data);
      setUser(data.user);
    },
    logout: async () => {
      try { await api.logout(refreshToken.current); } catch { /* si esce comunque */ }
      await dropSession();
    },
    // Dopo il cambio password l'API restituisce un nuovo refresh token.
    changePassword: async (data) => {
      const res = await api.changePassword(data);
      if (res?.refresh_token) {
        refreshToken.current = res.refresh_token;
        await saveRefreshToken(res.refresh_token);
      }
      setUser(await api.me());
    },
  }), [user, booting, storeTokens, dropSession]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
