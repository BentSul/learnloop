import { Link } from 'react-router-dom';

export default function NotFound() {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-3 text-center">
            <h1 className="text-3xl font-bold">Page not found</h1>
            <Link to="/" className="text-indigo-600 hover:underline">
                Go home
            </Link>
        </div>
    );
}
