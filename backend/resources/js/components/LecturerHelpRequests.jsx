import { useState } from 'react';
import { acknowledgeHelpRequest } from '../api/adaptive';
import { parseApiError } from '../api/client';
import { StatusBadge } from './StatusBadge';

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
 * Lecturer Help Requests tab (AGENTS.md §15).
 *
 * Displays GET /api/courses/{courseId}/help-requests items (oldest first) with the
 * student's current result and a "Mark as seen" button (POST /api/help-requests/{id}/acknowledge).
 */
export default function LecturerHelpRequests({ helpRequests = [], loading = false, onChanged }) {
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);

    const handleMarkAsSeen = async (id) => {
        setBusyId(id);
        setError(null);
        try {
            await acknowledgeHelpRequest(id);
            await onChanged?.();
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    if (loading) {
        return (
            <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Loading help requests…
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-lg font-semibold text-slate-900">Student Help Requests</h2>
                <p className="text-xs text-slate-600">
                    Students who asked for help on a topic in this course, oldest first.
                </p>
            </div>

            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700"
                >
                    {error}
                </div>
            )}

            {helpRequests.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No active help requests in this course right now.
                </div>
            ) : (
                <ul className="space-y-3">
                    {helpRequests.map((req) => {
                        const hasScore =
                            req.performance_display !== null &&
                            req.performance_display !== undefined;

                        return (
                            <li
                                key={req.id}
                                className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                            >
                                <div className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-base font-semibold text-slate-900">
                                            {req.learner?.name}
                                        </span>
                                        {req.learner?.email && (
                                            <span className="text-xs text-slate-600">
                                                ({req.learner.email})
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <span className="font-medium text-slate-800">
                                            Topic: <strong>{req.topic?.name}</strong>
                                        </span>
                                        {req.status && (
                                            <StatusBadge status={req.status} label={req.label} />
                                        )}
                                        {hasScore && (
                                            <span className="font-semibold text-slate-800">
                                                {req.performance_display}%
                                            </span>
                                        )}
                                    </div>

                                    {req.requested_at && (
                                        <p className="text-xs text-slate-500">
                                            Requested: {formatLocalDateTime(req.requested_at)}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    {req.acknowledged_at ? (
                                        <span className="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800">
                                            ✓ Seen ({formatLocalDateTime(req.acknowledged_at)})
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled={busyId === req.id}
                                            onClick={() => handleMarkAsSeen(req.id)}
                                            className="rounded-md bg-indigo-700 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-800 disabled:opacity-60"
                                        >
                                            {busyId === req.id ? 'Saving…' : 'Mark as seen'}
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
