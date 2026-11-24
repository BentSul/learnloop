import { ConfidenceBadge, StatusBadge } from './StatusBadge';

function formatLocalDate(isoString) {
    if (!isoString) return null;
    const d = new Date(isoString);
    if (Number.isNaN(d.getTime())) return isoString;
    return d.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/**
 * Displays one §13.4 topic performance item as returned by TopicPerformanceService.
 * Contains no calculation logic: performance_display, label, explanation, confidence_reasons
 * and conflict pairs are rendered directly from the API payload.
 */
export default function TopicPerformanceCard({
    topic,
    hasMasteryGoal = false,
    hasHelpRequest = false,
    onToggleGoal,
    onToggleHelp,
    onStartRevisionQuiz,
    busyAction = null,
    readOnly = false,
}) {
    const hasEvidence = topic.performance_display !== null && topic.performance_display !== undefined;
    // Spec §7.3: On learner-facing Needs help cards, the difficulty class label is not shown;
    // the confidence reason text is shown instead.
    const showDifficultyClass = Boolean(topic.difficulty_class) && topic.status !== 'needs_help';

    return (
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-semibold text-slate-900">{topic.topic_name}</h3>
                        <StatusBadge status={topic.status} label={topic.label} />
                        {topic.pending_count > 0 && (
                            <span className="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                                {topic.pending_count} awaiting marking
                            </span>
                        )}
                    </div>
                    <p className="mt-1.5 text-sm text-slate-600">{topic.explanation}</p>
                </div>

                {hasEvidence && (
                    <div className="text-right">
                        <div className="text-2xl font-bold text-slate-900">
                            {topic.performance_display}%
                        </div>
                        <div className="text-xs text-slate-500">
                            {topic.earned_marks} / {topic.max_marks} marks
                        </div>
                    </div>
                )}
            </div>

            {/* Metadata row: Coverage band, Confidence, Difficulty (except Needs help), Last assessed & Evidence age */}
            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-xs text-slate-600">
                <ConfidenceBadge confidence={topic.confidence} />

                {topic.coverage_band && (
                    <span className="rounded-md bg-slate-100 px-2 py-1 capitalize">
                        Coverage: <strong>{topic.coverage_band}</strong> ({topic.max_marks} marks)
                    </span>
                )}

                {showDifficultyClass && (
                    <span className="rounded-md bg-slate-100 px-2 py-1 capitalize">
                        Difficulty: <strong>{topic.difficulty_class}</strong>
                    </span>
                )}

                {topic.evidence_age && (
                    <span
                        className={`rounded-md px-2 py-1 capitalize ${
                            topic.evidence_age === 'outdated'
                                ? 'bg-amber-50 text-amber-800'
                                : 'bg-slate-100 text-slate-700'
                        }`}
                    >
                        Evidence age: <strong>{topic.evidence_age}</strong>
                    </span>
                )}

                {topic.last_assessed_at && (
                    <span className="rounded-md bg-slate-100 px-2 py-1">
                        Last assessed: <strong>{formatLocalDate(topic.last_assessed_at)}</strong>
                    </span>
                )}
            </div>

            {/* Confidence reason texts (§7.6) */}
            {Array.isArray(topic.confidence_reasons) && topic.confidence_reasons.length > 0 && (
                <ul className="mt-3 space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    {topic.confidence_reasons.map((r) => (
                        <li key={r.code} className="flex items-center gap-1.5">
                            <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                            <span>{r.text}</span>
                        </li>
                    ))}
                </ul>
            )}

            {/* Conflicting evidence details (§8.5, I5, T1) */}
            {topic.conflict?.conflict_detected && Array.isArray(topic.conflict.pairs) && (
                <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                    <div className="font-semibold">
                        Conflicting evidence detected
                        {topic.conflict.confidence_reduced
                            ? ' (confidence reduced by one level)'
                            : ' (limited evidence in at least one source — confidence not reduced)'}
                    </div>
                    <ul className="mt-1.5 space-y-1">
                        {topic.conflict.pairs.map((pair, idx) => (
                            <li key={idx}>
                                <strong>{pair.a.label}</strong>: {pair.a.earned_marks}/{pair.a.max_marks} (
                                {pair.a.performance_display}%) vs <strong>{pair.b.label}</strong>:{' '}
                                {pair.b.earned_marks}/{pair.b.max_marks} ({pair.b.performance_display}%) —{' '}
                                <span>{pair.difference_display} percentage-point difference</span>
                                {!pair.both_meet_minimum && (
                                    <span className="ml-1 text-amber-700">
                                        (fewer than 5 max marks in a source)
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Learner controls: mastery goal, request help, revision quiz */}
            {!readOnly && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {onToggleGoal && (
                            <button
                                type="button"
                                disabled={busyAction !== null}
                                onClick={() => onToggleGoal(topic.topic_id, hasMasteryGoal)}
                                className={`rounded-md border px-3 py-1.5 text-xs font-medium transition ${
                                    hasMasteryGoal
                                        ? 'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                                        : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {hasMasteryGoal ? '★ Mastery goal active' : '☆ Set mastery goal'}
                            </button>
                        )}
                        {onToggleHelp && (
                            <button
                                type="button"
                                disabled={busyAction !== null}
                                onClick={() => onToggleHelp(topic.topic_id, hasHelpRequest)}
                                className={`rounded-md border px-3 py-1.5 text-xs font-medium transition ${
                                    hasHelpRequest
                                        ? 'border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100'
                                        : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {hasHelpRequest ? '✓ Help requested' : 'Request help'}
                            </button>
                        )}
                    </div>

                    {onStartRevisionQuiz && (
                        <div className="flex flex-wrap items-center gap-2">
                            {topic.revision_quiz_available === false && (
                                <span className="text-xs text-slate-500">
                                    No practice questions for this topic yet
                                </span>
                            )}
                            <button
                                type="button"
                                disabled={
                                    busyAction !== null ||
                                    topic.revision_quiz_available === false
                                }
                                title={
                                    topic.revision_quiz_available === false
                                        ? 'No practice questions for this topic yet'
                                        : undefined
                                }
                                onClick={() => onStartRevisionQuiz(topic.topic_id)}
                                className="rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {busyAction === `revision-${topic.topic_id}`
                                    ? 'Starting quiz…'
                                    : 'Take revision quiz'}
                            </button>
                        </div>
                    )}
                </div>
            )}
        </article>
    );
}
