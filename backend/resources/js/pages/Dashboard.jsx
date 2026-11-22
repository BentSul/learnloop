import { useAuth } from '../auth/AuthContext';

// Placeholder only. Adaptive screens (topic performance, recommendations) will
// be built against the API contracts Claude defines; nothing is calculated here.
export default function Dashboard() {
    const { user } = useAuth();

    return (
        <section>
            <h1 className="text-2xl font-semibold">Hi, {user.name}</h1>
            <p className="mt-2 text-slate-600">
                Your topic performance and recommendations will appear here.
            </p>
            <div className="mt-6 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">
                Dashboard coming soon.
            </div>
        </section>
    );
}
