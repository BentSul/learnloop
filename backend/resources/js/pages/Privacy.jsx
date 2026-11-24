import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { deleteMyAccount, exportMyData } from '../api/adaptive';
import { parseApiError, tokenStore, UNAUTHENTICATED_EVENT } from '../api/client';
import { useAuth } from '../auth/AuthContext';

/**
 * Learner Privacy / My Data page (AGENTS.md §15).
 *
 * - Download my data: GET /api/me/data-export (Bearer token sent via client), saves JSON as a file.
 * - Delete my account: confirmation screen explaining what is deleted (LearnLoop account, quiz
 *   attempts, synced Canvas/Klenam results, goals, recommendations; Canvas itself is not touched).
 *   DELETE /api/me/account body {confirm: "DELETE MY ACCOUNT", password}. On 204, clears token
 *   and goes to /login.
 */
export default function Privacy() {
    const { user } = useAuth();
    const navigate = useNavigate();

    const [downloading, setDownloading] = useState(false);
    const [downloadFeedback, setDownloadFeedback] = useState(null);
    const [downloadError, setDownloadError] = useState(null);

    const [confirmText, setConfirmText] = useState('');
    const [password, setPassword] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});

    const handleDownloadData = async () => {
        setDownloading(true);
        setDownloadFeedback(null);
        setDownloadError(null);
        try {
            const token = tokenStore.get();
            const res = await fetch('/api/me/data-export', {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                },
            });

            if (!res.ok) {
                const fallbackData = await exportMyData();
                triggerJsonDownload(fallbackData);
            } else {
                const json = await res.json();
                triggerJsonDownload(json);
            }
            setDownloadFeedback('Your data export has been downloaded.');
        } catch (err) {
            setDownloadError(parseApiError(err).message);
        } finally {
            setDownloading(false);
        }
    };

    const triggerJsonDownload = (data) => {
        const blob = new Blob([JSON.stringify(data, null, 2)], {
            type: 'application/json',
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const today = new Date().toISOString().slice(0, 10);
        a.href = url;
        a.download = `learnloop-my-data-${today}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleDeleteAccount = async (e) => {
        e.preventDefault();
        setDeleting(true);
        setDeleteError(null);
        setFieldErrors({});
        try {
            await deleteMyAccount({
                confirm: confirmText.trim(),
                password: password || undefined,
            });
            tokenStore.clear();
            window.dispatchEvent(new Event(UNAUTHENTICATED_EVENT));
            navigate('/login', { replace: true });
        } catch (err) {
            const parsed = parseApiError(err);
            setDeleteError(parsed.message);
            setFieldErrors(parsed.fieldErrors ?? {});
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Privacy &amp; My Data</h1>
                <p className="mt-1 text-sm text-slate-600">
                    Export a copy of everything LearnLoop stores for your account ({user?.email}), or
                    permanently delete your account.
                </p>
            </div>

            {/* Download my data */}
            <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-semibold text-slate-900">Download my data</h2>
                <p className="mt-1 text-xs text-slate-600">
                    Download a JSON file containing your LearnLoop profile, connected courses, practice
                    quiz attempts and answers, synced external scores, mastery goals, help requests,
                    and recommendation history.
                </p>

                {downloadFeedback && (
                    <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-900">
                        {downloadFeedback}
                    </div>
                )}

                {downloadError && (
                    <div
                        role="alert"
                        className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-700"
                    >
                        {downloadError}
                    </div>
                )}

                <button
                    type="button"
                    disabled={downloading}
                    onClick={handleDownloadData}
                    className="mt-4 rounded-md bg-indigo-700 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-800 disabled:opacity-60"
                >
                    {downloading ? 'Preparing download…' : 'Download my data (.json)'}
                </button>
            </section>

            {/* Delete my account */}
            <section className="rounded-xl border border-rose-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-semibold text-rose-800">Delete my account</h2>
                <p className="mt-1 text-xs text-slate-700">
                    Deleting your account permanently removes from LearnLoop:
                </p>
                <ul className="mt-2 list-inside list-disc space-y-1 text-xs text-slate-700">
                    <li>Your LearnLoop user account and Canvas connection link</li>
                    <li>All of your practice and revision quiz attempts, answers, and mark versions</li>
                    <li>All synced Canvas and Klenam AI results stored for you in LearnLoop</li>
                    <li>Your mastery goals, help requests, and study recommendations</li>
                </ul>
                <p className="mt-2 text-xs font-medium text-slate-800">
                    Your actual Canvas account and grades in Canvas itself are never touched.
                </p>

                {deleteError && (
                    <div
                        role="alert"
                        className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-700"
                    >
                        {deleteError}
                    </div>
                )}

                <form onSubmit={handleDeleteAccount} className="mt-4 space-y-4 text-xs">
                    <div>
                        <label
                            htmlFor="delete_confirm_text"
                            className="mb-1 block font-medium text-slate-700"
                        >
                            Type <code>DELETE MY ACCOUNT</code> to confirm
                        </label>
                        <input
                            id="delete_confirm_text"
                            type="text"
                            required
                            autoComplete="off"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            placeholder="DELETE MY ACCOUNT"
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                        {Array.isArray(fieldErrors.confirm) &&
                            fieldErrors.confirm.map((m, idx) => (
                                <p key={idx} className="mt-1 text-xs text-rose-700">
                                    {m}
                                </p>
                            ))}
                    </div>

                    <div>
                        <label
                            htmlFor="delete_confirm_password"
                            className="mb-1 block font-medium text-slate-700"
                        >
                            Your password (leave blank if you signed in with Canvas)
                        </label>
                        <input
                            id="delete_confirm_password"
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                        {Array.isArray(fieldErrors.password) &&
                            fieldErrors.password.map((m, idx) => (
                                <p key={idx} className="mt-1 text-xs text-rose-700">
                                    {m}
                                </p>
                            ))}
                    </div>

                    <button
                        type="submit"
                        disabled={deleting || confirmText.trim() !== 'DELETE MY ACCOUNT'}
                        className="rounded-md bg-rose-700 px-4 py-2 text-xs font-medium text-white hover:bg-rose-800 disabled:opacity-50"
                    >
                        {deleting ? 'Deleting account…' : 'Delete my account'}
                    </button>
                </form>
            </section>
        </div>
    );
}
