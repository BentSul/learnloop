import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

const linkClass = ({ isActive }) =>
    `rounded-md px-3 py-2 text-sm font-medium ${
        isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100'
    }`;

/** Layout for signed-in pages: top navigation + page content. */
export default function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const isLecturerOrAdmin = user?.role === 'lecturer' || user?.role === 'admin';
    const isLecturerOnly = user?.role === 'lecturer';

    const navItems = [
        { to: '/dashboard', label: 'Dashboard' },
        ...(!isLecturerOnly ? [{ to: '/recommendations', label: 'Recommendations' }] : []),
        ...(isLecturerOrAdmin ? [{ to: '/lecturer', label: 'Lecturer Tools' }] : []),
        { to: '/canvas', label: 'Canvas' },
        { to: '/privacy', label: 'Privacy' },
    ];

    const handleLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    return (
        <div className="min-h-screen">
            {/* Lets keyboard and screen-reader users jump past the menu. */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-indigo-700 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
            >
                Skip to main content
            </a>
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto max-w-5xl px-4">
                    <div className="flex items-center justify-between gap-3 py-3">
                        <div className="flex min-w-0 items-center gap-6">
                            <NavLink to="/dashboard" className="shrink-0 text-lg font-bold text-indigo-600">
                                LearnLoop
                            </NavLink>
                            {/* Tablet and desktop: links beside the logo */}
                            <nav className="hidden flex-wrap gap-1 sm:flex" aria-label="Main">
                                {navItems.map((item) => (
                                    <NavLink key={item.to} to={item.to} className={linkClass}>
                                        {item.label}
                                    </NavLink>
                                ))}
                            </nav>
                        </div>
                        <div className="flex min-w-0 items-center gap-2 text-sm sm:gap-3">
                            <span className="truncate text-slate-700" title={user.name}>
                                {user.name}
                            </span>
                            <span className="hidden rounded-full bg-slate-100 px-2 py-0.5 text-xs capitalize text-slate-600 sm:inline">
                                {user.role}
                            </span>
                            <button
                                type="button"
                                onClick={handleLogout}
                                className="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-100"
                            >
                                Log out
                            </button>
                        </div>
                    </div>
                    {/* Phones: one row of links under the logo, scrolls sideways if needed */}
                    <nav className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-2 sm:hidden" aria-label="Main (mobile)">
                        {navItems.map((item) => (
                            <NavLink key={item.to} to={item.to} className={(state) => `${linkClass(state)} shrink-0 whitespace-nowrap`}>
                                {item.label}
                            </NavLink>
                        ))}
                    </nav>
                </div>
            </header>
            <main id="main-content" tabIndex={-1} className="mx-auto max-w-5xl px-4 py-6 focus:outline-none sm:py-8">
                <Outlet />
            </main>
        </div>
    );
}
