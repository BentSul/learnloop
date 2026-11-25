import { useEffect, useState } from 'react';
import { fetchTopicHistory } from '../api/adaptive';
import { parseApiError } from '../api/client';
import { StatusBadge } from './StatusBadge';
import { useDialog } from './useDialog';

/**
 * Learner "See progress" modal (AGENTS.md §15).
 *
 * Calls GET /api/me/topics/{topicId}/history -> {topic, points: [{date, sources, earned_marks,
 * max_marks, day_display, cumulative_display, status, label}]}.
 * - With fewer than 2 points, shows "Not enough history yet" instead of a chart.
 * - With 2+ points, shows an SVG line chart of cumulative_display by date (y axis 0–100%),
 *   with dashed threshold lines at 50% (Getting there) and 80% (Strong).
 * - Always provides a text table below listing each day's sources and day_display.
 */
export default function TopicProgressModal({ topicId, topicName, onClose }) {
    const dialogRef = useDialog(onClose);
    const [history, setHistory] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!topicId) return;
        let active = true;
        setLoading(true);
        setError(null);
        fetchTopicHistory(topicId)
            .then((res) => {
                if (active) setHistory(res);
            })
            .catch((err) => {
                if (active) setError(parseApiError(err).message);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [topicId]);

    if (!topicId) return null;

    const points = history?.points ?? [];
    const hasChart = points.length >= 2;

    // SVG dimensions
    const width = 520;
    const height = 200;
    const padLeft = 40;
    const padRight = 20;
    const padTop = 16;
    const padBottom = 30;
    const plotW = width - padLeft - padRight;
    const plotH = height - padTop - padBottom;

    const xFor = (i) =>
        points.length <= 1 ? padLeft + plotW / 2 : padLeft + (i / (points.length - 1)) * plotW;
    const yFor = (pct) => {
        const clamped = Math.max(0, Math.min(100, Number(pct) || 0));
        return padTop + plotH - (clamped / 100) * plotH;
    };

    const polylinePoints = points
        .map((pt, idx) => `${xFor(idx)},${yFor(pt.cumulative_display)}`)
        .join(' ');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div
                role="dialog"
                ref={dialogRef}
                tabIndex={-1}
                aria-modal="true"
                aria-labelledby="progress-modal-title"
                className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
            >
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="progress-modal-title" className="text-lg font-semibold text-slate-900">
                            Progress Over Time: {history?.topic?.name ?? topicName}
                        </h2>
                        <p className="mt-0.5 text-xs text-slate-600">
                            Cumulative topic score by assessment date, with 50% (Getting there) and
                            80% (Strong) thresholds.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                </div>

                {loading ? (
                    <p className="mt-6 text-sm text-slate-500">Loading progress history…</p>
                ) : error ? (
                    <div
                        role="alert"
                        className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700"
                    >
                        {error}
                    </div>
                ) : (
                    <div className="mt-4 space-y-5">
                        {!hasChart ? (
                            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-600">
                                Not enough history yet
                            </div>
                        ) : (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <svg
                                    viewBox={`0 0 ${width} ${height}`}
                                    role="img"
                                    aria-label={`Line chart of cumulative score over ${points.length} dates for ${history?.topic?.name ?? topicName}`}
                                    className="w-full overflow-visible"
                                >
                                    {/* Y-axis baseline 0% and 100% */}
                                    <line
                                        x1={padLeft}
                                        y1={yFor(0)}
                                        x2={width - padRight}
                                        y2={yFor(0)}
                                        stroke="#cbd5e1"
                                        strokeWidth="1"
                                    />
                                    <line
                                        x1={padLeft}
                                        y1={yFor(100)}
                                        x2={width - padRight}
                                        y2={yFor(100)}
                                        stroke="#e2e8f0"
                                        strokeWidth="1"
                                    />

                                    {/* 50% Getting there threshold (dashed) */}
                                    <line
                                        x1={padLeft}
                                        y1={yFor(50)}
                                        x2={width - padRight}
                                        y2={yFor(50)}
                                        stroke="#d97706"
                                        strokeWidth="1"
                                        strokeDasharray="4 4"
                                    />
                                    <text
                                        x={padLeft - 6}
                                        y={yFor(50) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="#b45309"
                                    >
                                        50%
                                    </text>

                                    {/* 80% Strong threshold (dashed) */}
                                    <line
                                        x1={padLeft}
                                        y1={yFor(80)}
                                        x2={width - padRight}
                                        y2={yFor(80)}
                                        stroke="#059669"
                                        strokeWidth="1"
                                        strokeDasharray="4 4"
                                    />
                                    <text
                                        x={padLeft - 6}
                                        y={yFor(80) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="#047857"
                                    >
                                        80%
                                    </text>

                                    <text
                                        x={padLeft - 6}
                                        y={yFor(0) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="#64748b"
                                    >
                                        0%
                                    </text>
                                    <text
                                        x={padLeft - 6}
                                        y={yFor(100) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="#64748b"
                                    >
                                        100%
                                    </text>

                                    {/* Cumulative progress line */}
                                    <polyline
                                        fill="none"
                                        stroke="#4338ca"
                                        strokeWidth="2.5"
                                        points={polylinePoints}
                                    />

                                    {/* Data points and date labels */}
                                    {points.map((pt, idx) => {
                                        const cx = xFor(idx);
                                        const cy = yFor(pt.cumulative_display);
                                        return (
                                            <g key={pt.date}>
                                                <circle
                                                    cx={cx}
                                                    cy={cy}
                                                    r="4"
                                                    fill="#4338ca"
                                                />
                                                <text
                                                    x={cx}
                                                    y={cy - 8}
                                                    textAnchor="middle"
                                                    fontSize="10"
                                                    fontWeight="600"
                                                    fill="#1e293b"
                                                >
                                                    {pt.cumulative_display}%
                                                </text>
                                                <text
                                                    x={cx}
                                                    y={height - 8}
                                                    textAnchor="middle"
                                                    fontSize="10"
                                                    fill="#475569"
                                                >
                                                    {pt.date}
                                                </text>
                                            </g>
                                        );
                                    })}
                                </svg>
                            </div>
                        )}

                        {/* Text version table (always provided for accessibility) */}
                        {points.length > 0 && (
                            <div
                                className="overflow-x-auto rounded-lg border border-slate-200"
                                tabIndex={0}
                                role="region"
                                aria-label="Progress by date (scrolls sideways on small screens)"
                            >
                                <table className="w-full text-left text-xs">
                                    <thead className="border-b border-slate-200 bg-slate-50 text-slate-700">
                                        <tr>
                                            <th className="px-3 py-2 font-semibold">Date</th>
                                            <th className="px-3 py-2 font-semibold">Sources</th>
                                            <th className="px-3 py-2 font-semibold">Day result</th>
                                            <th className="px-3 py-2 font-semibold">
                                                Cumulative result
                                            </th>
                                            <th className="px-3 py-2 font-semibold">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {points.map((pt) => (
                                            <tr key={pt.date}>
                                                <td className="px-3 py-2 font-medium text-slate-900">
                                                    {pt.date}
                                                </td>
                                                <td className="px-3 py-2 text-slate-700">
                                                    {Array.isArray(pt.sources)
                                                        ? pt.sources.join(', ')
                                                        : pt.sources}
                                                </td>
                                                <td className="px-3 py-2 text-slate-700">
                                                    {pt.day_display}% ({pt.earned_marks}/
                                                    {pt.max_marks})
                                                </td>
                                                <td className="px-3 py-2 font-semibold text-slate-900">
                                                    {pt.cumulative_display}%
                                                </td>
                                                <td className="px-3 py-2">
                                                    <StatusBadge
                                                        status={pt.status}
                                                        label={pt.label}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
