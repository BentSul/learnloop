import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { fetchCanvasAuthStatus, startCanvasAuthLogin } from '../api/auth';
import { parseApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import AuthCard from '../components/AuthCard';
import FormField from '../components/FormField';

// No role field: the API always creates a student. Lecturer/admin accounts are
// created by the team (UserSeeder), never through this form.
export default function Register() {
    const { register } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [error, setError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const [canvasOAuthEnabled, setCanvasOAuthEnabled] = useState(false);
    const [startingCanvasLogin, setStartingCanvasLogin] = useState(false);

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
            await register(form);
            navigate('/dashboard', { replace: true });
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
            title="Create your account"
            subtitle="Student accounts only."
            footer={
                <>
                    Already registered?{' '}
                    <Link to="/login" className="font-medium text-indigo-600 hover:underline">
                        Sign in
                    </Link>
                </>
            }
        >
            <div className="space-y-4">
                {error && Object.keys(fieldErrors).length === 0 && (
                    <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error}
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
                            <span>or create an account with email</span>
                            <div className="h-px flex-1 bg-slate-200" />
                        </div>
                    </>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        label="Full name"
                        name="name"
                        autoComplete="name"
                        value={form.name}
                        onChange={update}
                        errors={fieldErrors}
                    />
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
                        autoComplete="new-password"
                        value={form.password}
                        onChange={update}
                        errors={fieldErrors}
                    />
                    <FormField
                        label="Confirm password"
                        name="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={form.password_confirmation}
                        onChange={update}
                        errors={fieldErrors}
                    />
                    <button
                        type="submit"
                        disabled={submitting}
                        className="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {submitting ? 'Creating account…' : 'Create account'}
                    </button>
                </form>
            </div>
        </AuthCard>
    );
}
