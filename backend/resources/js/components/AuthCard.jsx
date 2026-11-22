import { Link } from 'react-router-dom';

/** Centered card used by the Login and Register pages. */
export default function AuthCard({ title, subtitle, children, footer }) {
    return (
        <div className="flex min-h-screen items-center justify-center px-4">
            <div className="w-full max-w-md rounded-xl bg-white p-8 shadow">
                <Link to="/" className="mb-6 block text-center text-2xl font-bold text-indigo-600">
                    LearnLoop
                </Link>
                <h1 className="text-xl font-semibold">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
                <div className="mt-6">{children}</div>
                {footer && <p className="mt-6 text-center text-sm text-slate-500">{footer}</p>}
            </div>
        </div>
    );
}
