import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchCanvasItems, saveCanvasMappings } from '../api/adaptive';
import { parseApiError } from '../api/client';

/**
 * Lecturer Canvas Mapping screen (AGENTS.md §12, v0.8 §3.3).
 *
 * Each Canvas assignment is mapped either as a whole item (canvas_criterion_id: '')
 * OR per rubric criterion, never both. All entries of one Canvas item share the item's
 * source_group ('canvas' | 'klenam').
 */
export default function LecturerCanvasMapping({ courseId, topics }) {
    const [items, setItems] = useState([]);
    const [drafts, setDrafts] = useState({});
    const [sourceByAssignment, setSourceByAssignment] = useState({});
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [errorList, setErrorList] = useState([]);
    const [needsCanvasReconnect, setNeedsCanvasReconnect] = useState(false);
    const [feedback, setFeedback] = useState(null);
    const activeTopics = topics.filter((t) => t.status !== 'archived');

    const hydrateDrafts = useCallback((canvasItems) => {
        const map = {};
        const sources = {};
        for (const item of canvasItems) {
            const existingCriterionSource = (item.criteria ?? []).find(
                (c) => c.mapping?.source_group,
            )?.mapping?.source_group;
            sources[item.canvas_assignment_id] =
                item.mapping?.source_group ?? existingCriterionSource ?? 'canvas';

            const wholeKey = `${item.canvas_assignment_id}|`;
            map[wholeKey] = {
                canvas_assignment_id: item.canvas_assignment_id,
                canvas_criterion_id: '',
                topic_id: item.mapping?.topic_id ?? null,
                difficulty: item.mapping?.difficulty ?? 'medium',
            };
            for (const c of item.criteria ?? []) {
                const critKey = `${item.canvas_assignment_id}|${c.canvas_criterion_id}`;
                map[critKey] = {
                    canvas_assignment_id: item.canvas_assignment_id,
                    canvas_criterion_id: c.canvas_criterion_id,
                    topic_id: c.mapping?.topic_id ?? null,
                    difficulty: c.mapping?.difficulty ?? 'medium',
                };
            }
        }
        setDrafts(map);
        setSourceByAssignment(sources);
    }, []);

    const loadItems = useCallback(async () => {
        setLoading(true);
        setError(null);
        setErrorList([]);
        try {
            const res = await fetchCanvasItems(courseId);
            const list = Array.isArray(res) ? res : res?.data ?? [];
            setItems(list);
            hydrateDrafts(list);
        } catch (err) {
            setError(parseApiError(err).message);
            if (err?.response?.status === 409) {
                setNeedsCanvasReconnect(true);
            }
        } finally {
            setLoading(false);
        }
    }, [courseId, hydrateDrafts]);

    useEffect(() => {
        loadItems();
    }, [loadItems]);

    const updateDraft = (assignmentId, criterionId, field, value) => {
        const key = `${assignmentId}|${criterionId}`;
        setDrafts((prev) => ({
            ...prev,
            [key]: {
                ...(prev[key] ?? {
                    canvas_assignment_id: assignmentId,
                    canvas_criterion_id: criterionId,
                    topic_id: null,
                    difficulty: 'medium',
                }),
                [field]: value,
            },
        }));
    };

    const updateSourceGroup = (assignmentId, sourceGroup) => {
        setSourceByAssignment((prev) => ({
            ...prev,
            [assignmentId]: sourceGroup,
        }));
    };

    const handleSaveAll = async () => {
        setSaving(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const mappings = Object.values(drafts).map((d) => ({
                canvas_assignment_id: String(d.canvas_assignment_id),
                canvas_criterion_id: String(d.canvas_criterion_id ?? ''),
                topic_id: d.topic_id ? Number(d.topic_id) : null,
                difficulty: d.difficulty || 'medium',
                source_group: sourceByAssignment[d.canvas_assignment_id] ?? 'canvas',
            }));
            const updated = await saveCanvasMappings(courseId, mappings);
            const list = Array.isArray(updated) ? updated : updated?.data ?? [];
            setItems(list);
            hydrateDrafts(list);
            setFeedback(
                'Canvas mappings saved. Learners’ evidence will be updated on their next sync.',
            );
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
            if (err?.response?.status === 409) {
                setNeedsCanvasReconnect(true);
            }
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Loading Canvas assignments and rubric criteria…
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">
                        Canvas Assignment & Rubric Topic Mapping
                    </h2>
                    <p className="text-xs text-slate-500">
                        Map each Canvas item either as a whole to one topic, or map individual
                        rubric criteria to topics. Default difficulty is Medium.
                    </p>
                </div>

                <button
                    type="button"
                    disabled={saving}
                    onClick={handleSaveAll}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {saving ? 'Saving mappings…' : 'Save all mappings'}
                </button>
            </div>

            {feedback && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {feedback}
                </div>
            )}

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    <div>
                        <span>{error}</span>
                        {needsCanvasReconnect && (
                            <Link
                                to="/canvas"
                                className="ml-2 font-semibold underline hover:text-rose-900"
                            >
                                Go to Canvas connection →
                            </Link>
                        )}
                    </div>
                    {errorList.length > 0 && (
                        <ul className="mt-1.5 list-inside list-disc text-xs">
                            {errorList.map((e, i) => (
                                <li key={i}>{e}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <div className="space-y-4">
                {items.map((item) => {
                    const wholeKey = `${item.canvas_assignment_id}|`;
                    const wholeDraft = drafts[wholeKey] ?? {
                        topic_id: null,
                        difficulty: 'medium',
                    };
                    const itemSource = sourceByAssignment[item.canvas_assignment_id] ?? 'canvas';
                    const hasRubric = Array.isArray(item.criteria) && item.criteria.length > 0;
                    const isExternalTool =
                        Array.isArray(item.submission_types) &&
                        item.submission_types.includes('external_tool');

                    return (
                        <article
                            key={item.canvas_assignment_id}
                            className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="text-base font-semibold text-slate-900">
                                            {item.name}
                                        </h3>
                                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                            {item.points_possible} pts
                                        </span>
                                        {!wholeDraft.topic_id &&
                                            !(item.criteria ?? []).some(
                                                (c) =>
                                                    drafts[
                                                        `${item.canvas_assignment_id}|${c.canvas_criterion_id}`
                                                    ]?.topic_id,
                                            ) && (
                                                <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">
                                                    Not mapped
                                                </span>
                                            )}
                                    </div>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Canvas ID: {item.canvas_assignment_id}
                                        {Array.isArray(item.submission_types) &&
                                            item.submission_types.length > 0 &&
                                            ` · ${item.submission_types.join(', ')}`}
                                    </p>
                                    {isExternalTool && (
                                        <p className="mt-1 text-xs font-medium text-indigo-700">
                                            Looks like an external tool (e.g. Klenam)
                                        </p>
                                    )}
                                </div>

                                {/* Per-item Source select + Whole-item mapping controls */}
                                <div className="flex flex-wrap items-center gap-2 text-xs">
                                    <label className="flex items-center gap-1.5 font-medium text-slate-600">
                                        <span>Source:</span>
                                        <select
                                            aria-label={`Source for ${item.name}`}
                                            value={itemSource}
                                            onChange={(e) =>
                                                updateSourceGroup(
                                                    item.canvas_assignment_id,
                                                    e.target.value,
                                                )
                                            }
                                            className="rounded-md border border-slate-300 px-2.5 py-1.5 font-normal text-slate-800"
                                        >
                                            <option value="canvas">Canvas</option>
                                            <option value="klenam">Klenam AI</option>
                                        </select>
                                    </label>

                                    <span className="ml-1 font-medium text-slate-600">
                                        Whole item:
                                    </span>
                                    <select
                                        value={wholeDraft.topic_id ?? ''}
                                        onChange={(e) =>
                                            updateDraft(
                                                item.canvas_assignment_id,
                                                '',
                                                'topic_id',
                                                e.target.value ? Number(e.target.value) : null,
                                            )
                                        }
                                        className="rounded-md border border-slate-300 px-2.5 py-1.5"
                                    >
                                        <option value="">— Not mapped —</option>
                                        {activeTopics.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.name ?? t.topic_name}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        value={wholeDraft.difficulty ?? 'medium'}
                                        disabled={!wholeDraft.topic_id}
                                        onChange={(e) =>
                                            updateDraft(
                                                item.canvas_assignment_id,
                                                '',
                                                'difficulty',
                                                e.target.value,
                                            )
                                        }
                                        className="rounded-md border border-slate-300 px-2.5 py-1.5 disabled:bg-slate-100"
                                    >
                                        <option value="easy">Easy</option>
                                        <option value="medium">Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>
                            </div>

                            {/* Rubric criteria mapping controls */}
                            {hasRubric && (
                                <div className="mt-4 border-t border-slate-100 pt-3">
                                    <div className="mb-2 text-xs font-semibold text-slate-700">
                                        Rubric Criteria (map either the whole item above or criteria
                                        below, not both):
                                    </div>
                                    <div className="space-y-2">
                                        {item.criteria.map((c) => {
                                            const cKey = `${item.canvas_assignment_id}|${c.canvas_criterion_id}`;
                                            const cDraft = drafts[cKey] ?? {
                                                topic_id: null,
                                                difficulty: 'medium',
                                            };

                                            return (
                                                <div
                                                    key={c.canvas_criterion_id}
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs"
                                                >
                                                    <div>
                                                        <span className="font-medium text-slate-800">
                                                            {c.description}
                                                        </span>{' '}
                                                        <span className="text-slate-500">
                                                            ({c.points} pts)
                                                        </span>
                                                    </div>

                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <select
                                                            value={cDraft.topic_id ?? ''}
                                                            onChange={(e) =>
                                                                updateDraft(
                                                                    item.canvas_assignment_id,
                                                                    c.canvas_criterion_id,
                                                                    'topic_id',
                                                                    e.target.value
                                                                        ? Number(e.target.value)
                                                                        : null,
                                                                )
                                                            }
                                                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1"
                                                        >
                                                            <option value="">
                                                                — Not mapped —
                                                            </option>
                                                            {activeTopics.map((t) => (
                                                                <option key={t.id} value={t.id}>
                                                                    {t.name ?? t.topic_name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <select
                                                            value={cDraft.difficulty ?? 'medium'}
                                                            disabled={!cDraft.topic_id}
                                                            onChange={(e) =>
                                                                updateDraft(
                                                                    item.canvas_assignment_id,
                                                                    c.canvas_criterion_id,
                                                                    'difficulty',
                                                                    e.target.value,
                                                                )
                                                            }
                                                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1 disabled:bg-slate-100"
                                                        >
                                                            <option value="easy">Easy</option>
                                                            <option value="medium">Medium</option>
                                                            <option value="hard">Hard</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </article>
                    );
                })}
            </div>
        </div>
    );
}
