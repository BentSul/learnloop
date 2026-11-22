import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';

function FullPageMessage({ children }) {
    return <div className="flex min-h-screen items-center justify-center text-slate-500">{children}</div>;
}

/** Routes that need a signed-in user. */
export function RequireAuth() {
    const { user, loading } = useAuth();
    const location = useLocation();

    if (loading) return <FullPageMessage>Loading…</FullPageMessage>;
    if (!user) return <Navigate to="/login" replace state={{ from: location }} />;
    return <Outlet />;
}

/** Login/register: signed-in users are sent to the dashboard instead. */
export function GuestOnly() {
    const { user, loading } = useAuth();

    if (loading) return <FullPageMessage>Loading…</FullPageMessage>;
    if (user) return <Navigate to="/dashboard" replace />;
    return <Outlet />;
}
