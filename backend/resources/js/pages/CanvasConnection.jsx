import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    connectCanvasToken,
    disconnectCanvasToken,
    fetchCanvasStatus,
    startCanvasOAuthConnect,
} from '../api/adaptive';
import { CANVAS_ERROR_MESSAGES } from '../api/auth';
import { parseApiError } from '../api/client';

function formatLocalDateTime(isoString) {
    if (!isoString) return null;
    const d = new Date(isoString);
    if (Number.isNaN(d.getTime())) return isoString;
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Canvas Connection page (AGENTS.md §13–§14).
 *
 * - When oauth_available is true, shows "Connect with Canvas" as the main option and keeps
 *   the token box as "Use an access token instead (testing)".
 * - Handles /canvas?canvas_connected=1 and /canvas?canvas_error=<code> redirects.
 * - Password-type input for the user's personal Canvas access token, cleared immediately on submit.
 * - Shows "Demo data mode" note only when adapter === 'fixture'.
 */
export default function CanvasConnection() {
    const [searchParams, setSearchParams] = useSearchParams();

    const [status, setStatus] = useState(null);
    const [tokenInput, setTokenInput] = useState('');
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [startingOAuth, setStartingOAuth] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [feedback, setFeedback] = useState(null);
    const [error, setError] = useState(null);
    const [errorList, setErrorList] = useState([]);

    const loadStatus = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetchCanvasStatus();
            setStatus(res);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        const connectedParam = searchParams.get('canvas_connected');
        const errorParam = searchParams.get('canvas_error');
        if (connectedParam === '1') {
            setFeedback('Canvas connected');
            setSearchParams({}, { replace: true });
        } else if (errorParam) {
            setError(
                CANVAS_ERROR_MESSAGES[errorParam] ??
                    'Canvas connection could not be completed.',
            );
            setSearchParams({}, { replace: true });
        }
        loadStatus();
    }, [loadStatus, searchParams, setSearchParams]);

    const handleOAuthConnect = async () => {
        setStartingOAuth(true);
        setFeedback(null);
        setError(null);
        setErrorList([]);
        try {
            const res = await startCanvasOAuthConnect();
            if (res?.authorize_url) {
                window.location.href = res.authorize_url;
                return;
            }
            setError('Could not start Canvas OAuth connection.');
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setStartingOAuth(false);
        }
    };

    const handleConnect = async (e) => {
        e.preventDefault();
        const submittedToken = tokenInput;
        // Never keep or display the token after submitting (AGENTS.md §13).
        setTokenInput('');
        setSubmitting(true);
        setFeedback(null);
        setError(null);
        setErrorList([]);

        try {
            const nextStatus = await connectCanvasToken(submittedToken);
            setStatus(nextStatus);
            setFeedback('Canvas connected');
        } catch (err) {
            const data = err?.response?.data;
            const fieldErrs = data?.errors
                ? Array.isArray(data.errors)
                    ? data.errors
                    : Object.values(data.errors).flat()
                : [];
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(fieldErrs);
        } finally {
            setSubmitting(false);
        }
    };

    const handleDisconnect = async () => {
        setDisconnecting(true);
        setFeedback(null);
        setError(null);
        setErrorList([]);
        try {
            await disconnectCanvasToken();
            await loadStatus();
            setFeedback(
                'Disconnected from Canvas. Your token has been deleted; previously synced evidence remains in your learning history.',
            );
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setDisconnecting(false);
        }
    };

    const isFixtureMode = status?.adapter === 'fixture';
    const oauthAvailable = Boolean(status?.oauth_available);

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Canvas Connection</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Link your Canvas account so LearnLoop can sync your courses and grades.
                    </p>
                </div>

                {isFixtureMode && (
                    <span className="rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800">
                        Demo data mode
                    </span>
                )}
            </div>

            {feedback && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {feedback}
                </div>
            )}

            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
                >
                    <div>{error}</div>
                    {errorList.length > 0 && (
                        <ul className="mt-1.5 list-inside list-disc text-xs">
                            {errorList.map((msg, idx) => (
                                <li key={idx}>{msg}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {/* Current connection status */}
            <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="text-base font-semibold text-slate-900">
                            Connection Status
                        </h2>
                        {loading ? (
                            <p className="mt-1 text-sm text-slate-500">
                                Checking Canvas connection…
                            </p>
                        ) : status?.connected ? (
                            <div className="mt-2 space-y-1 text-sm text-slate-700">
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                        Connected
                                    </span>
                                    {status.canvas_user_id && (
                                        <span className="text-xs text-slate-500">
                                            Canvas user ID: <strong>{status.canvas_user_id}</strong>
                                        </span>
                                    )}
                                </div>
                                {status.connected_at && (
                                    <p className="text-xs text-slate-500">
                                        Connected at: {formatLocalDateTime(status.connected_at)}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="mt-2 flex items-center gap-2">
                                <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                    Not connected
                                </span>
                                <span className="text-xs text-slate-500">
                                    No active Canvas connection is linked to your account.
                                </span>
                            </div>
                        )}
                    </div>

                    {status?.connected && (
                        <button
                            type="button"
                            disabled={disconnecting}
                            onClick={handleDisconnect}
                            className="rounded-md border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                        >
                            {disconnecting ? 'Disconnecting…' : 'Disconnect'}
                        </button>
                    )}
                </div>
            </section>

            {/* Primary OAuth option when oauth_available is true (AGENTS.md §14) */}
            {oauthAvailable && (
                <section className="rounded-xl border border-indigo-200 bg-indigo-50/60 p-6 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-900">
                        Connect with Canvas
                    </h2>
                    <p className="mt-1 text-xs text-slate-600">
                        Authorize LearnLoop directly in Canvas without copying an access token.
                    </p>
                    <button
                        type="button"
                        disabled={startingOAuth}
                        onClick={handleOAuthConnect}
                        className="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {startingOAuth ? 'Redirecting to Canvas…' : 'Connect with Canvas'}
                    </button>
                </section>
            )}

            {/* Access token box (main option when OAuth is off; "Use an access token instead (testing)" when OAuth is available) */}
            <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-base font-semibold text-slate-900">
                    {oauthAvailable
                        ? 'Use an access token instead (testing)'
                        : status?.connected
                          ? 'Replace Canvas Access Token'
                          : 'Connect with an Access Token'}
                </h2>
                <div className="mt-2 rounded-lg bg-slate-50 p-3.5 text-xs text-slate-700">
                    <p className="font-semibold text-slate-900">How to get your Canvas token:</p>
                    <ol className="mt-1.5 list-inside list-decimal space-y-1">
                        <li>
                            Open Canvas and go to <strong>Account → Settings</strong>.
                        </li>
                        <li>
                            Scroll to Approved Integrations and click{' '}
                            <strong>+ New Access Token</strong>.
                        </li>
                        <li>
                            Enter a purpose (for example, <code>LearnLoop</code>), generate the
                            token, and paste it below.
                        </li>
                    </ol>
                </div>

                <form onSubmit={handleConnect} className="mt-4 space-y-4">
                    <div>
                        <label
                            htmlFor="canvas_access_token"
                            className="mb-1 block text-xs font-medium text-slate-700"
                        >
                            Canvas Access Token
                        </label>
                        <input
                            id="canvas_access_token"
                            name="access_token"
                            type="password"
                            autoComplete="off"
                            value={tokenInput}
                            onChange={(e) => setTokenInput(e.target.value)}
                            placeholder="Paste your Canvas access token"
                            required
                            minLength={20}
                            maxLength={500}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            Your token is encrypted on the server and is never stored in your
                            browser or shown again.
                        </p>
                    </div>

                    <button
                        type="submit"
                        disabled={submitting || tokenInput.trim().length < 20}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {submitting ? 'Verifying with Canvas…' : 'Connect Canvas'}
                    </button>
                </form>
            </section>
        </div>
    );
}
