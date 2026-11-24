import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as authApi from '../api/auth';
import { tokenStore, UNAUTHENTICATED_EVENT } from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    // Only "loading" if there is a stored token we still have to validate.
    const [loading, setLoading] = useState(() => Boolean(tokenStore.get()));

    useEffect(() => {
        if (!tokenStore.get()) return;
        authApi
            .fetchMe()
            .then(setUser)
            .catch(() => tokenStore.clear())
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        const onUnauthenticated = () => setUser(null);
        window.addEventListener(UNAUTHENTICATED_EVENT, onUnauthenticated);
        return () => window.removeEventListener(UNAUTHENTICATED_EVENT, onUnauthenticated);
    }, []);

    const signIn = useCallback(({ token, user: nextUser }) => {
        tokenStore.set(token);
        setUser(nextUser);
    }, []);

    const login = useCallback(
        async (email, password) => signIn(await authApi.login(email, password)),
        [signIn],
    );

    const register = useCallback(
        async (fields) => signIn(await authApi.register(fields)),
        [signIn],
    );

    const completeTokenSignIn = useCallback(async (token) => {
        tokenStore.set(token);
        const me = await authApi.fetchMe();
        setUser(me);
        return me;
    }, []);

    const logout = useCallback(async () => {
        try {
            await authApi.logout();
        } catch {
            // Token may already be invalid; sign out locally regardless.
        }
        tokenStore.clear();
        setUser(null);
    }, []);

    const value = useMemo(
        () => ({ user, loading, login, register, logout, completeTokenSignIn }),
        [user, loading, login, register, logout, completeTokenSignIn],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
    return ctx;
}
