import { useState } from 'react';

/**
 * Convert a local datetime-local input value ("YYYY-MM-DDTHH:mm") into an
 * ISO 8601 string with local timezone offset (spec §10.10).
 */
function toIsoWithOffset(localValue) {
    const date = new Date(localValue);
    if (Number.isNaN(date.getTime())) return null;
    const tzOffsetMin = -date.getTimezoneOffset();
    const sign = tzOffsetMin >= 0 ? '+' : '-';
    const pad = (n) => String(Math.floor(Math.abs(n))).padStart(2, '0');
    const hours = pad(tzOffsetMin / 60);
    const minutes = pad(tzOffsetMin % 60);

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}:00${sign}${hours}:${minutes}`
    );
}

function defaultTomorrowLocal() {
    const d = new Date(Date.now() + 24 * 60 * 60 * 1000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function PostponeModal({ recommendation, onClose, onConfirm, submitting }) {
    const [datetimeLocal, setDatetimeLocal] = useState(defaultTomorrowLocal);
    const [validationError, setValidationError] = useState(null);

    if (!recommendation) return null;

    const handleSubmit = (e) => {
        e.preventDefault();
        setValidationError(null);
        const chosen = new Date(datetimeLocal);
        const now = new Date();
        const maxDate = new Date(now.getTime() + 60 * 24 * 60 * 60 * 1000);

        if (Number.isNaN(chosen.getTime()) || chosen <= now) {
            setValidationError('Please choose a date and time in the future.');
            return;
        }
        if (chosen > maxDate) {
            setValidationError('Reminder must be no more than 60 days ahead.');
            return;
        }

        const iso = toIsoWithOffset(datetimeLocal);
        onConfirm(recommendation.id, iso);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 className="text-lg font-semibold text-slate-900">Remind me later</h2>
                <p className="mt-1 text-sm text-slate-600">
                    Choose when to bring back the recommendation for{' '}
                    <strong>{recommendation.topic?.name}</strong> (up to 60 days ahead).
                </p>

                <form onSubmit={handleSubmit} className="mt-4 space-y-4">
                    {validationError && (
                        <p role="alert" className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {validationError}
                        </p>
                    )}

                    <div>
                        <label htmlFor="reminder_at" className="mb-1 block text-xs font-medium text-slate-700">
                            Reminder date & time
                        </label>
                        <input
                            id="reminder_at"
                            type="datetime-local"
                            value={datetimeLocal}
                            onChange={(e) => setDatetimeLocal(e.target.value)}
                            required
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            {submitting ? 'Saving…' : 'Set reminder'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
