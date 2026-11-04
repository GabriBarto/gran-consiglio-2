import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api, bindSession } from './api.js';

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

// Il refresh token resta in sessionStorage: si perde chiudendo la scheda (scelta prudente per un pannello admin).
const KEY = 'toogood.admin.refresh_token';
const store = {
  get: () => { try { return sessionStorage.getItem(KEY); } catch { return null; } },
  set: (v) => { try { sessionStorage.setItem(KEY, v); } catch { /* ignora */ } },
  clear: () => { try { sessionStorage.removeItem(KEY); } catch { /* ignora */ } },
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);
  const accessToken = useRef(null);
  const refreshToken = useRef(null);
  const refreshing = useRef(null);

  const storeTokens = useCallback((data) => {
    accessToken.current = data.access_token;
    refreshToken.current = data.refresh_token;
    store.set(data.refresh_token);
  }, []);

  const dropSession = useCallback(() => {
    accessToken.current = null;
    refreshToken.current = null;
    store.clear();
    setUser(null);
  }, []);

  // Una sola richiesta di refresh alla volta: il refresh token è monouso.
  const refresh = useCallback(() => {
    if (!refreshToken.current) return Promise.resolve(false);
    if (!refreshing.current) {
      refreshing.current = api.refresh(refreshToken.current)
        .then((data) => { storeTokens(data); return true; })
        .catch(() => { dropSession(); return false; })
        .finally(() => { refreshing.current = null; });
    }
    return refreshing.current;
  }, [storeTokens, dropSession]);

  useEffect(() => {
    bindSession({ getAccessToken: () => accessToken.current, refresh });
  }, [refresh]);

  useEffect(() => {
    (async () => {
      refreshToken.current = store.get();
      if (refreshToken.current && (await refresh())) {
        try { setUser(await api.me()); } catch { dropSession(); }
      }
      setBooting(false);
    })();
  }, [refresh, dropSession]);

  const value = useMemo(() => ({
    user,
    booting,
    startLogin: (email, password) => api.login(email, password),
    finishLogin: async (challengeId, code) => {
      const data = await api.verifyLogin(challengeId, code);
      // Il pannello è solo per l'admin: un altro ruolo non entra e non lascia token.
      if (data.user.role !== 'ADMIN') {
        try { await api.logout(data.refresh_token); } catch { /* ignora */ }
        const err = new Error('forbidden');
        err.code = 'FORBIDDEN_ROLE';
        throw err;
      }
      storeTokens(data);
      setUser(data.user);
    },
    logout: async () => {
      try { await api.logout(refreshToken.current); } catch { /* si esce comunque */ }
      dropSession();
    },
    changePassword: async (data) => {
      const res = await api.changePassword(data);
      if (res?.refresh_token) {
        refreshToken.current = res.refresh_token;
        store.set(res.refresh_token);
      }
      setUser(await api.me());
    },
  }), [user, booting, storeTokens, dropSession]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
