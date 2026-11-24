import { useCallback, useEffect, useState } from 'react';
import {
    createTopicResource,
    deleteTopicResource,
    fetchTopicResources,
    suggestTopicVideos,
    updateTopicResource,
} from '../api/adaptive';
import { parseApiError } from '../api/client';

const KIND_LABELS = {
    video: 'Video',
    reading: 'Reading',
    practice: 'Practice',
    other: 'Other',
};

const KIND_ICONS = {
    video: '▶',
    reading: '📖',
    practice: '✍',
    other: '🔗',
};

/**
 * Lecturer study resources curator for a selected topic (AGENTS.md §15).
 *
 * - Add, edit, reorder (position), and delete resources.
 * - "Suggest videos" button shown ONLY when videoSuggestionsEnabled is true.
 * - "Suggested videos — review" list with Approve (PATCH status: "published") and Remove (DELETE).
 */
export default function LecturerTopicResources({
    topicId,
    topicName,
    videoSuggestionsEnabled = false,
}) {
    const [resources, setResources] = useState([]);
    const [loading, setLoading] = useState(true);
    const [suggesting, setSuggesting] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);
    const [errorList, setErrorList] = useState([]);
    const [feedback, setFeedback] = useState(null);

    // Create / Edit modal state
    const [editingResource, setEditingResource] = useState(null); // null | 'new' | resource object
    const [form, setForm] = useState({
        kind: 'reading',
        title: '',
        url: '',
        note: '',
    });
    const [savingForm, setSavingForm] = useState(false);

    const loadResources = useCallback(async (tid) => {
        if (!tid) return;
        setLoading(true);
        setError(null);
        setErrorList([]);
        try {
            const res = await fetchTopicResources(tid);
            setResources(res?.data ?? (Array.isArray(res) ? res : []));
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (topicId) {
            setFeedback(null);
            loadResources(topicId);
        }
    }, [topicId, loadResources]);

    const published = resources
        .filter((r) => r.status !== 'suggested')
        .sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
    const suggested = resources.filter((r) => r.status === 'suggested');

    const openCreateModal = () => {
        setEditingResource('new');
        setForm({ kind: 'reading', title: '', url: '', note: '' });
    };

    const openEditModal = (res) => {
        setEditingResource(res);
        setForm({
            kind: res.kind ?? 'reading',
            title: res.title ?? '',
            url: res.url ?? '',
            note: res.note ?? '',
        });
    };

    const handleSaveForm = async (e) => {
        e.preventDefault();
        setSavingForm(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);

        const payload = {
            kind: form.kind,
            title: form.title.trim(),
            url: form.url.trim(),
            note: form.note.trim() || null,
        };

        try {
            if (editingResource === 'new') {
                await createTopicResource(topicId, payload);
                setFeedback('Resource added.');
            } else {
                await updateTopicResource(editingResource.id, payload);
                setFeedback('Resource updated.');
            }
            setEditingResource(null);
            await loadResources(topicId);
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setSavingForm(false);
        }
    };

    const handleDelete = async (resId) => {
        setBusyId(resId);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            await deleteTopicResource(resId);
            setFeedback('Resource removed.');
            await loadResources(topicId);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleApproveSuggestion = async (resId) => {
        setBusyId(resId);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            await updateTopicResource(resId, { status: 'published' });
            setFeedback('Suggested video approved and published for students.');
            await loadResources(topicId);
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setBusyId(null);
        }
    };

    const handleMovePosition = async (res, direction) => {
        const idx = published.findIndex((item) => item.id === res.id);
        const swapIdx = idx + direction;
        if (idx === -1 || swapIdx < 0 || swapIdx >= published.length) return;

        const neighbor = published[swapIdx];
        const nextPos = neighbor.position ?? swapIdx + 1;
        const neighborPos = res.position ?? idx + 1;

        setBusyId(res.id);
        setError(null);
        try {
            await Promise.all([
                updateTopicResource(res.id, { position: nextPos }),
                updateTopicResource(neighbor.id, {
                    position: nextPos === neighborPos ? neighborPos + direction : neighborPos,
                }),
            ]);
            await loadResources(topicId);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleSuggestVideos = async () => {
        setSuggesting(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const res = await suggestTopicVideos(topicId);
            setResources(res?.data ?? (Array.isArray(res) ? res : []));
            setFeedback('YouTube video suggestions loaded for your review.');
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setSuggesting(false);
        }
    };

    return (
        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-slate-900">
                        Study Resources: {topicName}
                    </h3>
                    <p className="text-xs text-slate-600">
                        Curate videos, readings, and practice links shown in &ldquo;Learn
                        more&rdquo; on topic and recommendation cards.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {videoSuggestionsEnabled && (
                        <button
                            type="button"
                            disabled={suggesting}
                            onClick={handleSuggestVideos}
                            className="rounded-md border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:opacity-60"
                        >
                            {suggesting ? 'Searching YouTube…' : 'Suggest videos'}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={openCreateModal}
                        className="rounded-md bg-indigo-700 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-800"
                    >
                        + Add resource
                    </button>
                </div>
            </div>

            {feedback && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-900">
                    {feedback}
                </div>
            )}

            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-700"
                >
                    <div>{error}</div>
                    {errorList.length > 0 && (
                        <ul className="mt-1 list-inside list-disc">
                            {errorList.map((item, idx) => (
                                <li key={idx}>{item}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {/* Suggested videos — review list (§15) */}
            {suggested.length > 0 && (
                <div className="rounded-lg border border-amber-200 bg-amber-50/70 p-4">
                    <h4 className="text-xs font-semibold text-amber-950">
                        Suggested videos — review (students do not see these until approved)
                    </h4>
                    <ul className="mt-2.5 space-y-2.5">
                        {suggested.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-white p-3 text-xs"
                            >
                                <div className="min-w-0 flex-1">
                                    <a
                                        href={item.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="font-semibold text-indigo-700 hover:underline"
                                    >
                                        {item.title}
                                        <span className="sr-only"> (opens in a new tab)</span>
                                    </a>
                                    {item.note && (
                                        <p className="mt-0.5 text-slate-600">{item.note}</p>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={busyId === item.id}
                                        onClick={() => handleApproveSuggestion(item.id)}
                                        className="rounded-md bg-emerald-700 px-3 py-1 font-medium text-white hover:bg-emerald-800 disabled:opacity-60"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        disabled={busyId === item.id}
                                        onClick={() => handleDelete(item.id)}
                                        className="rounded-md border border-rose-200 bg-rose-50 px-3 py-1 font-medium text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Published resources list */}
            {loading ? (
                <p className="text-xs text-slate-500">Loading study resources…</p>
            ) : published.length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-xs text-slate-600">
                    No published study resources for this topic yet.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    {published.map((res, idx) => (
                        <li
                            key={res.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs"
                        >
                            <div className="flex min-w-0 flex-1 items-start gap-2.5">
                                <span
                                    aria-hidden="true"
                                    className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-slate-100 text-[11px]"
                                >
                                    {KIND_ICONS[res.kind] ?? KIND_ICONS.other}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <a
                                            href={res.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-semibold text-indigo-700 hover:underline"
                                        >
                                            {res.title}
                                            <span className="sr-only"> (opens in a new tab)</span>
                                        </a>
                                        <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] capitalize text-slate-700">
                                            {KIND_LABELS[res.kind] ?? res.kind}
                                        </span>
                                    </div>
                                    {res.note && (
                                        <p className="mt-0.5 text-slate-600">{res.note}</p>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    disabled={idx === 0 || busyId === res.id}
                                    onClick={() => handleMovePosition(res, -1)}
                                    aria-label={`Move ${res.title} up`}
                                    className="rounded border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    disabled={idx === published.length - 1 || busyId === res.id}
                                    onClick={() => handleMovePosition(res, 1)}
                                    aria-label={`Move ${res.title} down`}
                                    className="rounded border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                >
                                    ↓
                                </button>
                                <button
                                    type="button"
                                    onClick={() => openEditModal(res)}
                                    className="rounded border border-slate-300 px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    disabled={busyId === res.id}
                                    onClick={() => handleDelete(res.id)}
                                    className="rounded border border-rose-200 bg-rose-50 px-2.5 py-1 font-medium text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                                >
                                    Delete
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {/* Add / Edit Resource Modal */}
            {editingResource && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="resource-modal-title"
                        className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl"
                    >
                        <h3 id="resource-modal-title" className="text-lg font-semibold text-slate-900">
                            {editingResource === 'new' ? 'Add Study Resource' : 'Edit Study Resource'}
                        </h3>

                        <form onSubmit={handleSaveForm} className="mt-4 space-y-4 text-xs">
                            <div>
                                <label
                                    htmlFor="resource_kind"
                                    className="mb-1 block font-medium text-slate-700"
                                >
                                    Type
                                </label>
                                <select
                                    id="resource_kind"
                                    value={form.kind}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, kind: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-2.5 py-1.5"
                                >
                                    <option value="video">Video</option>
                                    <option value="reading">Reading</option>
                                    <option value="practice">Practice</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div>
                                <label
                                    htmlFor="resource_title"
                                    className="mb-1 block font-medium text-slate-700"
                                >
                                    Title
                                </label>
                                <input
                                    id="resource_title"
                                    type="text"
                                    required
                                    maxLength={200}
                                    value={form.title}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, title: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="resource_url"
                                    className="mb-1 block font-medium text-slate-700"
                                >
                                    Web address (http:// or https://)
                                </label>
                                <input
                                    id="resource_url"
                                    type="url"
                                    required
                                    placeholder="https://developer.mozilla.org/..."
                                    value={form.url}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, url: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="resource_note"
                                    className="mb-1 block font-medium text-slate-700"
                                >
                                    Short note (optional)
                                </label>
                                <textarea
                                    id="resource_note"
                                    rows={2}
                                    maxLength={1000}
                                    value={form.note}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, note: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setEditingResource(null)}
                                    className="rounded-md border border-slate-300 px-3.5 py-1.5 text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingForm}
                                    className="rounded-md bg-indigo-700 px-4 py-1.5 font-medium text-white hover:bg-indigo-800 disabled:opacity-60"
                                >
                                    {savingForm ? 'Saving…' : 'Save resource'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </section>
    );
}
