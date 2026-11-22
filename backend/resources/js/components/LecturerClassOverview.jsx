import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchClassOverview } from '../api/adaptive';
import { parseApiError } from '../api/client';
import { StatusBadge } from './StatusBadge';

const STATUS_SHORT_LABELS = {
    strong: 'Strong',
    strong_unconfirmed: 'Strong so far',
    getting_there: 'Getting there',
    needs_help: 'Needs help',
    not_enough_evidence: 'Not assessed',
};

/**
 * Lecturer Class Overview (AGENTS.md §12, ClassOverviewService::forCourse).
 * Shows per-topic status distribution counts and per-learner performance cells,
 * linking to /lecturer/courses/{courseId}/learners/{learnerId}.
 */
export default function LecturerClassOverview({ courseId }) {
    const [overview, setOverview] = useState({ topics: [], learners: [] });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const loadOverview = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetchClassOverview(courseId);
            setOverview({
                topics: res?.topics ?? [],
                learners: res?.learners ?? [],
            });
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [courseId]);

    useEffect(() => {
        loadOverview();
    }, [loadOverview]);

    if (loading) {
        return (
            <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Loading class overview…
            </div>
        );
    }

    if (error) {
        return (
            <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {error}
            </div>
        );
    }

    const { topics, learners } = overview;

    return (
        <div className="space-y-8">
            {/* Per-topic status distribution summary */}
            <section className="space-y-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">
                        Class Topic Distribution
                    </h2>
                    <p className="text-xs text-slate-500">
                        Learner count in each status band per active topic.
                    </p>
                </div>

                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
                            <tr>
                                <th className="px-4 py-3 font-semibold">Topic</th>
                                <th className="px-3 py-3 text-center font-semibold text-rose-700">
                                    Needs help
                                </th>
                                <th className="px-3 py-3 text-center font-semibold text-amber-700">
                                    Getting there
                                </th>
                                <th className="px-3 py-3 text-center font-semibold text-teal-700">
                                    Strong so far
                                </th>
                                <th className="px-3 py-3 text-center font-semibold text-emerald-700">
                                    Strong
                                </th>
                                <th className="px-3 py-3 text-center font-semibold text-slate-500">
                                    Not assessed
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {topics.map((t) => (
                                <tr key={t.topic_id ?? t.topic_name}>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {t.topic_name}
                                    </td>
                                    <td className="px-3 py-3 text-center font-semibold text-rose-700">
                                        {t.counts?.needs_help ?? 0}
                                    </td>
                                    <td className="px-3 py-3 text-center font-semibold text-amber-700">
                                        {t.counts?.getting_there ?? 0}
                                    </td>
                                    <td className="px-3 py-3 text-center font-semibold text-teal-700">
                                        {t.counts?.strong_unconfirmed ?? 0}
                                    </td>
                                    <td className="px-3 py-3 text-center font-semibold text-emerald-700">
                                        {t.counts?.strong ?? 0}
                                    </td>
                                    <td className="px-3 py-3 text-center text-slate-500">
                                        {t.counts?.not_enough_evidence ?? 0}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* Per-learner matrix */}
            <section className="space-y-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">
                        Learner Performance Overview
                    </h2>
                    <p className="text-xs text-slate-500">
                        Select any learner to inspect their full per-topic evidence, confidence
                        reasons, and source conflict details.
                    </p>
                </div>

                {learners.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                        No Canvas students have connected to LearnLoop in this course yet.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Learner</th>
                                    {topics.map((t) => (
                                        <th key={t.topic_id ?? t.topic_name} className="px-3 py-3 font-semibold">
                                            {t.topic_name}
                                        </th>
                                    ))}
                                    <th className="px-4 py-3 text-right font-semibold">Detail</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {learners.map((row) => {
                                    const cellByTopic = {};
                                    for (const cell of row.topics ?? []) {
                                        cellByTopic[cell.topic_id] = cell;
                                    }

                                    return (
                                        <tr key={row.learner.id}>
                                            <td className="px-4 py-3 font-semibold text-slate-900">
                                                {row.learner.name}
                                            </td>
                                            {topics.map((t) => {
                                                const cell = cellByTopic[t.topic_id];
                                                if (!cell) {
                                                    return (
                                                        <td
                                                            key={t.topic_id ?? t.topic_name}
                                                            className="px-3 py-3 text-slate-400"
                                                        >
                                                            —
                                                        </td>
                                                    );
                                                }
                                                return (
                                                    <td
                                                        key={t.topic_id ?? t.topic_name}
                                                        className="px-3 py-3 align-top"
                                                    >
                                                        <div className="space-y-1">
                                                            <StatusBadge
                                                                status={cell.status}
                                                                label={
                                                                    cell.performance_display !==
                                                                        null &&
                                                                    cell.performance_display !==
                                                                        undefined
                                                                        ? `${cell.performance_display}% · ${
                                                                              STATUS_SHORT_LABELS[
                                                                                  cell.status
                                                                              ] ?? cell.status
                                                                          }`
                                                                        : STATUS_SHORT_LABELS[
                                                                              cell.status
                                                                          ] ?? cell.status
                                                                }
                                                            />
                                                            <div className="flex flex-wrap gap-1 text-[11px] text-slate-500">
                                                                {cell.confidence && (
                                                                    <span className="capitalize">
                                                                        {cell.confidence} conf.
                                                                    </span>
                                                                )}
                                                                {cell.conflict && (
                                                                    <span className="font-semibold text-amber-700">
                                                                        · Conflict
                                                                    </span>
                                                                )}
                                                                {cell.pending_count > 0 && (
                                                                    <span className="text-indigo-600">
                                                                        · {cell.pending_count}{' '}
                                                                        pending
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </td>
                                                );
                                            })}
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    to={`/lecturer/courses/${courseId}/learners/${row.learner.id}`}
                                                    className="font-medium text-indigo-600 hover:underline"
                                                >
                                                    View learner →
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </div>
    );
}
