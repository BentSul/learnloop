const KIND_META = {
    video: { icon: '▶', label: 'Video' },
    reading: { icon: '📖', label: 'Reading' },
    practice: { icon: '✍', label: 'Practice' },
    other: { icon: '🔗', label: 'Resource' },
};

/**
 * "Learn more" study resources section on topic and recommendation cards (AGENTS.md §15).
 *
 * Shows an icon per kind, the title as a link (opening in a new tab with screen-reader text),
 * the note underneath, and always a "Find videos on YouTube" link when findVideosUrl is present.
 */
export default function LearnMoreSection({ resources = [], findVideosUrl = null }) {
    const hasResources = Array.isArray(resources) && resources.length > 0;
    if (!hasResources && !findVideosUrl) return null;

    return (
        <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50/70 p-3.5 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-semibold text-slate-800">Learn more</span>
                {findVideosUrl && (
                    <a
                        href={findVideosUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 font-medium text-indigo-700 hover:underline"
                    >
                        <span aria-hidden="true">▶</span>
                        <span>Find videos on YouTube</span>
                        <span className="sr-only"> (opens in a new tab)</span>
                    </a>
                )}
            </div>

            {hasResources && (
                <ul className="mt-2.5 space-y-2">
                    {resources.map((res) => {
                        const meta = KIND_META[res.kind] ?? KIND_META.other;
                        return (
                            <li key={res.id} className="flex items-start gap-2">
                                <span
                                    aria-hidden="true"
                                    title={meta.label}
                                    className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-white text-[11px] shadow-xs"
                                >
                                    {meta.icon}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <a
                                            href={res.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-medium text-indigo-700 hover:underline"
                                        >
                                            {res.title}
                                            <span className="sr-only"> (opens in a new tab)</span>
                                        </a>
                                        <span className="rounded bg-slate-200/70 px-1.5 py-0.2 text-[10px] capitalize text-slate-700">
                                            {meta.label}
                                        </span>
                                    </div>
                                    {res.note && (
                                        <p className="mt-0.5 text-slate-600">{res.note}</p>
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
