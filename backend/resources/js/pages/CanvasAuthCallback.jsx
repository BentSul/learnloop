import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { tokenStore } from '../api/client';
import { useAuth } from '../auth/AuthContext';

/**
 * SPA callback route for Sign in with Canvas: /auth/canvas#token=<api token>
 * (AGENTS.md §14).
 *
 * Reads the token from window.location.hash, stores it like a normal login token,
 * clears the hash with history.replaceState, loads /api/auth/me, and goes to /dashboard.
 */
export default function CanvasAuthCallback() {
    const { completeTokenSignIn } = useAuth();
    const navigate = useNavigate();
    const [error, setError] = useState(null);

    useEffect(() => {
        const rawHash = window.location.hash.startsWith('#')
            ? window.location.hash.slice(1)
            : window.location.hash;
        const params = new URLSearchParams(rawHash);
        const token = params.get('token');

        // Always clear the fragment immediately so the token never lingers in the address bar.
        window.history.replaceState(
            null,
            '',
            window.location.pathname + window.location.search,
        );

        if (!token) {
            setError('No sign-in token was received from Canvas.');
            return;
        }

        completeTokenSignIn(token)
            .then(() => {
                navigate('/dashboard', { replace: true });
            })
            .catch(() => {
                tokenStore.clear();
                setError('Could not complete Canvas sign-in. Please try again.');
            });
    }, [completeTokenSignIn, navigate]);

    if (error) {
        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 px-4 text-center">
                <div
                    role="alert"
                    className="max-w-md rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
                >
                    {error}
                </div>
                <Link to="/login" className="text-sm font-medium text-indigo-600 hover:underline">
                    ← Return to sign in
                </Link>
            </div>
        );
    }

    return (
        <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">
            Completing Canvas sign-in…
        </div>
    );
}
