import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { parseApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import AuthCard from '../components/AuthCard';
import FormField from '../components/FormField';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: '' });
    const [error, setError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

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
            <form onSubmit={submit} className="space-y-4">
                {error && (
                    <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error}
                    </p>
                )}
                <FormField label="Email" name="email" type="email" autoComplete="email" value={form.email} onChange={update} errors={fieldErrors} />
                <FormField label="Password" name="password" type="password" autoComplete="current-password" value={form.password} onChange={update} errors={fieldErrors} />
                <button
                    type="submit"
                    disabled={submitting}
                    className="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {submitting ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </AuthCard>
    );
}
