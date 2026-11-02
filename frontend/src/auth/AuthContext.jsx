import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { apiRequest, setAccessToken } from '../api/client';
import { getOrCreateDeviceId } from '../offline/device';

const AuthContext = createContext(null);

const STORAGE_KEY = 'pos.auth.session';

function loadStoredSession() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function persistSession(session) {
  try {
    if (session) {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    } else {
      window.localStorage.removeItem(STORAGE_KEY);
    }
  } catch {
    // Storage unavailable (private mode, etc.) - session just won't survive a refresh.
  }
}

export function AuthProvider({ children }) {
  const [session, setSession] = useState(() => {
    const stored = loadStoredSession();
    if (stored?.accessToken) {
      setAccessToken(stored.accessToken);
    }
    return stored;
  });

  const login = useCallback(async (shopCode, username, password) => {
    const deviceId = getOrCreateDeviceId();
    const data = await apiRequest('/auth/login', {
      method: 'POST',
      body: {
        shop_code: shopCode,
        username,
        password,
        device_uuid: deviceId,
        device_name: navigator.userAgent,
      },
    });

    if (data.two_factor_required) {
      return {
        twoFactorRequired: true,
        twoFactorToken: data.two_factor_token,
      };
    }

    setAccessToken(data.access_token);
    const nextSession = {
      accessToken: data.access_token,
      refreshToken: data.refresh_token,
      user: data.user,
      tenant: data.tenant,
      deviceId,
    };
    setSession(nextSession);
    persistSession(nextSession);

    return nextSession;
  }, []);

  const verifyTwoFactor = useCallback(async (twoFactorToken, code) => {
    const data = await apiRequest('/auth/2fa/verify', {
      method: 'POST',
      body: {
        two_factor_token: twoFactorToken,
        code,
      },
    });

    const deviceId = getOrCreateDeviceId();
    setAccessToken(data.access_token);
    const nextSession = {
      accessToken: data.access_token,
      refreshToken: data.refresh_token,
      user: data.user,
      tenant: data.tenant,
      deviceId,
    };
    setSession(nextSession);
    persistSession(nextSession);

    return nextSession;
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiRequest('/auth/logout', { method: 'POST' });
    } finally {
      setAccessToken(null);
      setSession(null);
      persistSession(null);
    }
  }, []);

  const value = useMemo(
    () => ({ session, login, verifyTwoFactor, logout }),
    [session, login, verifyTwoFactor, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
}
