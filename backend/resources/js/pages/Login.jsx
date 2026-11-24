import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import {
    CANVAS_ERROR_MESSAGES,
    fetchCanvasAuthStatus,
    startCanvasAuthLogin,
} from '../api/auth';
import { parseApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import AuthCard from '../components/AuthCard';
import FormField from '../components/FormField';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [searchParams] = useSearchParams();

    const [form, setForm] = useState({ email: '', password: '' });
    const [error, setError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const [canvasOAuthEnabled, setCanvasOAuthEnabled] = useState(false);
    const [startingCanvasLogin, setStartingCanvasLogin] = useState(false);

    const canvasErrorCode = searchParams.get('canvas_error');
    const canvasErrorMessage = canvasErrorCode
        ? CANVAS_ERROR_MESSAGES[canvasErrorCode] ?? 'Canvas sign-in could not be completed.'
        : null;

    useEffect(() => {
        let active = true;
        fetchCanvasAuthStatus()
            .then((res) => {
                if (active) setCanvasOAuthEnabled(Boolean(res?.enabled));
            })
            .catch(() => {
                if (active) setCanvasOAuthEnabled(false);
            });
        return () => {
            active = false;
        };
    }, []);

    const update = (e) => setForm((f) => ({ ...f, [e.target.name]: e.target.value }));

    const submit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setError(null);
        setFieldErrors({});
        try {
            await login(form.email, form.password);
            navigate(location.state?.from?.pathname ?? '/dashboard', { replace: true });
        } catch (err) {
            const parsed = parseApiError(err);
            setError(parsed.message);
            setFieldErrors(parsed.fieldErrors);
        } finally {
            setSubmitting(false);
        }
    };

    const handleCanvasSignIn = async () => {
        setStartingCanvasLogin(true);
        setError(null);
        try {
            const res = await startCanvasAuthLogin();
            if (res?.authorize_url) {
                window.location.href = res.authorize_url;
                return;
            }
            setError('Could not start Canvas sign-in.');
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setStartingCanvasLogin(false);
        }
    };

    return (
        <AuthCard
            title="Sign in"
            subtitle="Welcome back to LearnLoop."
            footer={
                <>
                    New here?{' '}
                    <Link to="/register" className="font-medium text-indigo-600 hover:underline">
                        Create an account
                    </Link>
                </>
            }
        >
            <div className="space-y-4">
                {(error || canvasErrorMessage) && (
                    <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error ?? canvasErrorMessage}
                    </p>
                )}

                {canvasOAuthEnabled && (
                    <>
                        <button
                            type="button"
                            disabled={startingCanvasLogin}
                            onClick={handleCanvasSignIn}
                            className="w-full rounded-md border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-60"
                        >
                            {startingCanvasLogin ? 'Redirecting to Canvas…' : 'Sign in with Canvas'}
                        </button>
                        <div className="flex items-center gap-3 text-xs text-slate-400">
                            <div className="h-px flex-1 bg-slate-200" />
                            <span>or sign in with email</span>
                            <div className="h-px flex-1 bg-slate-200" />
                        </div>
                    </>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        label="Email"
                        name="email"
                        type="email"
                        autoComplete="email"
                        value={form.email}
                        onChange={update}
                        errors={fieldErrors}
                    />
                    <FormField
                        label="Password"
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        value={form.password}
                        onChange={update}
                        errors={fieldErrors}
                    />
                    <button
                        type="submit"
                        disabled={submitting}
                        className="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {submitting ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>
        </AuthCard>
    );
}
