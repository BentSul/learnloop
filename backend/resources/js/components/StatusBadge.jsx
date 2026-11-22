const STATUS_STYLES = {
    strong: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    strong_unconfirmed: 'bg-teal-100 text-teal-800 border-teal-200',
    getting_there: 'bg-amber-100 text-amber-800 border-amber-200',
    needs_help: 'bg-rose-100 text-rose-800 border-rose-200',
    not_enough_evidence: 'bg-slate-100 text-slate-700 border-slate-200',
};

const PRIORITY_STYLES = {
    high: 'bg-rose-100 text-rose-800 border-rose-200',
    medium: 'bg-amber-100 text-amber-800 border-amber-200',
    low: 'bg-sky-100 text-sky-800 border-sky-200',
    optional: 'bg-slate-100 text-slate-700 border-slate-200',
};

const CONFIDENCE_STYLES = {
    high: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    moderate: 'bg-amber-50 text-amber-700 border-amber-200',
    limited: 'bg-slate-100 text-slate-700 border-slate-300',
};

export function StatusBadge({ status, label }) {
    const cls = STATUS_STYLES[status] ?? STATUS_STYLES.not_enough_evidence;
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${cls}`}>
            {label ?? status}
        </span>
    );
}

export function PriorityBadge({ priority }) {
    const cls = PRIORITY_STYLES[priority] ?? PRIORITY_STYLES.optional;
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide ${cls}`}>
            {priority} priority
        </span>
    );
}

export function ConfidenceBadge({ confidence }) {
    if (!confidence) {
        return (
            <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs text-slate-500">
                Confidence unavailable
            </span>
        );
    }
    const cls = CONFIDENCE_STYLES[confidence] ?? CONFIDENCE_STYLES.limited;
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}>
            {confidence} confidence
        </span>
    );
}
