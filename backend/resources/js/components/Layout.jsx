import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

// Add entries here as screens are built; no adaptive screens yet.
const NAV_ITEMS = [{ to: '/dashboard', label: 'Dashboard' }];

const linkClass = ({ isActive }) =>
    `rounded-md px-3 py-2 text-sm font-medium ${
        isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100'
    }`;

/** Layout for signed-in pages: top navigation + page content. */
export default function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    return (
        <div className="min-h-screen">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-6">
                        <span className="text-lg font-bold text-indigo-600">LearnLoop</span>
                        <nav className="flex gap-1" aria-label="Main">
                            {NAV_ITEMS.map((item) => (
                                <NavLink key={item.to} to={item.to} className={linkClass}>
                                    {item.label}
                                </NavLink>
                            ))}
                        </nav>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="text-slate-700">{user.name}</span>
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs capitalize text-slate-600">
                            {user.role}
                        </span>
                        <button
                            type="button"
                            onClick={handleLogout}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-100"
                        >
                            Log out
                        </button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    );
}
