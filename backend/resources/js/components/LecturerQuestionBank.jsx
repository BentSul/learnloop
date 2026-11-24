import { useCallback, useEffect, useState } from 'react';
import {
    archiveQuestion,
    createCourseTopic,
    createTopicQuestion,
    deleteCourseTopic,
    fetchLearningSourcesStatus,
    fetchTopicQuestions,
    importKlenamQuestions,
    regradeQuestion,
    updateCourseTopic,
    updateQuestion,
} from '../api/adaptive';
import { parseApiError } from '../api/client';
import LecturerQuizApiSearch from './LecturerQuizApiSearch';
import LecturerTopicResources from './LecturerTopicResources';

const DEFAULT_MCQ_OPTIONS = [
    { id: 'a', text: '' },
    { id: 'b', text: '' },
    { id: 'c', text: '' },
    { id: 'd', text: '' },
];

const TRUE_FALSE_OPTIONS = [
    { id: 'true', text: 'True' },
    { id: 'false', text: 'False' },
];

function isQuestionUsed(question) {
    return Boolean(
        question?.used_in_attempts ||
            question?.is_used ||
            question?.used ||
            (typeof question?.attempts_count === 'number' && question.attempts_count > 0) ||
            (typeof question?.attempt_items_count === 'number' && question.attempt_items_count > 0),
    );
}

export default function LecturerQuestionBank({ courseId, topics, onTopicsChanged }) {
    const [selectedTopicId, setSelectedTopicId] = useState(() => topics[0]?.id ?? null);
    const [questions, setQuestions] = useState([]);
    const [loadingQuestions, setLoadingQuestions] = useState(false);
    const [error, setError] = useState(null);
    const [errorList, setErrorList] = useState([]);
    const [feedback, setFeedback] = useState(null);

    // Topic Create / Edit modal state (AGENTS.md §13)
    const [editingTopic, setEditingTopic] = useState(null); // null = closed, 'new' or topic object
    const [topicForm, setTopicForm] = useState({
        name: '',
        description: '',
        learning_outcomes_text: '',
    });
    const [savingTopic, setSavingTopic] = useState(false);
    const [togglingTopicId, setTogglingTopicId] = useState(null);
    const [deleteTargetTopic, setDeleteTargetTopic] = useState(null);
    const [deleteConflictMessage, setDeleteConflictMessage] = useState(null);
    const [deletingTopic, setDeletingTopic] = useState(false);

    // Question Create / Edit modal state
    const [editingQuestion, setEditingQuestion] = useState(null); // null = closed, 'new' or question object
    const [forceLockedUsed, setForceLockedUsed] = useState(false);
    const [form, setForm] = useState({
        question_type: 'mcq',
        question_text: '',
        difficulty: 'medium',
        max_marks: '2.00',
        options: DEFAULT_MCQ_OPTIONS,
        correct_option_ids: ['a'],
        explanation: '',
    });
    const [savingForm, setSavingForm] = useState(false);

    // Regrade modal state
    const [regradeTarget, setRegradeTarget] = useState(null);
    const [regradeOptions, setRegradeOptions] = useState([]);
    const [regradeCorrectIds, setRegradeCorrectIds] = useState([]);
    const [regrading, setRegrading] = useState(false);

    // Klenam import state
    const [importFile, setImportFile] = useState(null);
    const [importFormat, setImportFormat] = useState('json');
    const [importing, setImporting] = useState(false);
    const [importReport, setImportReport] = useState(null);

    // Optional outside sources (§15: video_suggestions, question_search)
    const [learningSources, setLearningSources] = useState({
        video_suggestions: false,
        question_search: false,
    });
    const [showQuizApiSearch, setShowQuizApiSearch] = useState(false);

    useEffect(() => {
        let active = true;
        fetchLearningSourcesStatus()
            .then((res) => {
                if (active) {
                    setLearningSources({
                        video_suggestions: Boolean(res?.video_suggestions),
                        question_search: Boolean(res?.question_search),
                    });
                }
            })
            .catch(() => {});
        return () => {
            active = false;
        };
    }, []);

    useEffect(() => {
        if (topics.length > 0 && !topics.some((t) => Number(t.id) === Number(selectedTopicId))) {
            setSelectedTopicId(topics[0].id);
        }
    }, [topics, selectedTopicId]);

    const loadQuestions = useCallback(async (topicId) => {
        if (!topicId) return;
        setLoadingQuestions(true);
        setError(null);
        setErrorList([]);
        try {
            const res = await fetchTopicQuestions(topicId);
            setQuestions(Array.isArray(res) ? res : res?.data ?? []);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoadingQuestions(false);
        }
    }, []);

    useEffect(() => {
        if (selectedTopicId) {
            loadQuestions(selectedTopicId);
            setImportReport(null);
        }
    }, [selectedTopicId, loadQuestions]);

    const selectedTopic = topics.find((t) => Number(t.id) === Number(selectedTopicId)) ?? null;

    // ------------------------------------------------------------------ Topics (§13)

    const openCreateTopicModal = () => {
        setEditingTopic('new');
        setTopicForm({
            name: '',
            description: '',
            learning_outcomes_text: '',
        });
    };

    const openEditTopicModal = (topic, e) => {
        e?.stopPropagation();
        setEditingTopic(topic);
        setTopicForm({
            name: topic.name ?? topic.topic_name ?? '',
            description: topic.description ?? '',
            learning_outcomes_text: Array.isArray(topic.learning_outcomes)
                ? topic.learning_outcomes.join('\n')
                : '',
        });
    };

    const handleSaveTopic = async (e) => {
        e.preventDefault();
        setSavingTopic(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);

        const outcomes = topicForm.learning_outcomes_text
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');

        const payload = {
            name: topicForm.name.trim(),
            description: topicForm.description.trim() || null,
            learning_outcomes: outcomes.length > 0 ? outcomes : null,
        };

        try {
            if (editingTopic === 'new') {
                const created = await createCourseTopic(courseId, payload);
                setFeedback(`Topic "${created.name}" created.`);
                setEditingTopic(null);
                await onTopicsChanged?.();
                if (created?.id) {
                    setSelectedTopicId(created.id);
                }
            } else {
                const updated = await updateCourseTopic(editingTopic.id, payload);
                setFeedback(`Topic "${updated.name}" updated.`);
                setEditingTopic(null);
                await onTopicsChanged?.();
            }
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setSavingTopic(false);
        }
    };

    const handleToggleTopicArchive = async (topic, e) => {
        e?.stopPropagation();
        const nextStatus = topic.status === 'archived' ? 'active' : 'archived';
        setTogglingTopicId(topic.id);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const updated = await updateCourseTopic(topic.id, { status: nextStatus });
            setFeedback(
                updated.status === 'archived'
                    ? `Topic "${updated.name}" archived. It is hidden from learners while its evidence is retained.`
                    : `Topic "${updated.name}" restored to active.`,
            );
            await onTopicsChanged?.();
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setTogglingTopicId(null);
        }
    };

    const openDeleteTopicDialog = (topic, e) => {
        e?.stopPropagation();
        setDeleteTargetTopic(topic);
        setDeleteConflictMessage(null);
    };

    const handleConfirmDeleteTopic = async () => {
        if (!deleteTargetTopic) return;
        setDeletingTopic(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const deletedName = deleteTargetTopic.name ?? deleteTargetTopic.topic_name;
            const deletedId = deleteTargetTopic.id;
            await deleteCourseTopic(deletedId);
            setDeleteTargetTopic(null);
            setDeleteConflictMessage(null);
            setFeedback(`Topic "${deletedName}" deleted.`);
            if (Number(selectedTopicId) === Number(deletedId)) {
                setSelectedTopicId(null);
            }
            await onTopicsChanged?.();
        } catch (err) {
            if (err?.response?.status === 409) {
                setDeleteConflictMessage(
                    err?.response?.data?.message ??
                        'Students already have results for this topic, so it can only be archived.',
                );
            } else {
                const data = err?.response?.data;
                setError(data?.message ?? parseApiError(err).message);
                setErrorList(Array.isArray(data?.errors) ? data.errors : []);
                setDeleteTargetTopic(null);
            }
        } finally {
            setDeletingTopic(false);
        }
    };

    const handleArchiveInsteadOfDelete = async () => {
        if (!deleteTargetTopic) return;
        setDeletingTopic(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const updated = await updateCourseTopic(deleteTargetTopic.id, { status: 'archived' });
            setDeleteTargetTopic(null);
            setDeleteConflictMessage(null);
            setFeedback(
                `Topic "${updated.name}" archived. It is hidden from learners while their history is kept.`,
            );
            await onTopicsChanged?.();
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setDeletingTopic(false);
        }
    };

    // ------------------------------------------------------------------ Questions (§12)

    const openCreateModal = () => {
        setEditingQuestion('new');
        setForceLockedUsed(false);
        setForm({
            question_type: 'mcq',
            question_text: '',
            difficulty: 'medium',
            max_marks: '2.00',
            options: DEFAULT_MCQ_OPTIONS.map((o) => ({ ...o })),
            correct_option_ids: ['a'],
            explanation: '',
        });
    };

    const openEditModal = (q, markUsed = false) => {
        const opts = q.answer_data?.options ?? DEFAULT_MCQ_OPTIONS;
        const correct = (q.answer_data?.correct_option_ids ?? ['a']).map(String);
        setEditingQuestion(q);
        setForceLockedUsed(markUsed || isQuestionUsed(q));
        setForm({
            question_type: q.question_type ?? 'mcq',
            question_text: q.question_text ?? '',
            difficulty: q.difficulty ?? 'medium',
            max_marks: String(q.max_marks ?? '1.00'),
            options: opts.map((o) => ({ id: String(o.id), text: String(o.text ?? '') })),
            correct_option_ids: correct,
            explanation: q.explanation ?? '',
        });
    };

    const handleTypeChange = (nextType) => {
        if (nextType === 'true_false') {
            setForm((f) => ({
                ...f,
                question_type: 'true_false',
                options: TRUE_FALSE_OPTIONS.map((o) => ({ ...o })),
                correct_option_ids: ['true'],
            }));
        } else {
            setForm((f) => ({
                ...f,
                question_type: nextType,
                options: DEFAULT_MCQ_OPTIONS.map((o) => ({ ...o })),
                correct_option_ids: ['a'],
            }));
        }
    };

    const handleSaveQuestion = async (e) => {
        e.preventDefault();
        if (!selectedTopicId) return;
        setSavingForm(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);

        try {
            if (editingQuestion === 'new') {
                const payload = {
                    question_type: form.question_type,
                    question_text: form.question_text,
                    difficulty: form.difficulty,
                    max_marks: Number(form.max_marks),
                    answer_data: {
                        options: form.options,
                        correct_option_ids: form.correct_option_ids,
                    },
                    explanation: form.explanation || null,
                };
                await createTopicQuestion(selectedTopicId, payload);
                setFeedback('Question created.');
            } else {
                const payload = forceLockedUsed
                    ? {
                          question_text: form.question_text,
                          difficulty: form.difficulty,
                          explanation: form.explanation || null,
                      }
                    : {
                          question_type: form.question_type,
                          question_text: form.question_text,
                          difficulty: form.difficulty,
                          max_marks: Number(form.max_marks),
                          answer_data: {
                              options: form.options,
                              correct_option_ids: form.correct_option_ids,
                          },
                          explanation: form.explanation || null,
                      };
                await updateQuestion(editingQuestion.id, payload);
                setFeedback('Question updated.');
            }
            setEditingQuestion(null);
            await loadQuestions(selectedTopicId);
            onTopicsChanged?.();
        } catch (err) {
            const data = err?.response?.data;
            const errs = Array.isArray(data?.errors) ? data.errors : [];
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(errs);
            if (errs.some((msg) => String(msg).includes('has been used'))) {
                setForceLockedUsed(true);
            }
        } finally {
            setSavingForm(false);
        }
    };

    const handleArchive = async (questionId) => {
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            await archiveQuestion(questionId);
            setFeedback('Question archived (inactive). Past evidence still counts.');
            await loadQuestions(selectedTopicId);
            onTopicsChanged?.();
        } catch (err) {
            setError(parseApiError(err).message);
        }
    };

    const openRegradeModal = (q) => {
        const opts = q.answer_data?.options ?? [];
        const correct = (q.answer_data?.correct_option_ids ?? []).map(String);
        setRegradeTarget(q);
        setRegradeOptions(opts.map((o) => ({ id: String(o.id), text: String(o.text ?? '') })));
        setRegradeCorrectIds(correct);
    };

    const handleRegradeSubmit = async (e) => {
        e.preventDefault();
        if (!regradeTarget) return;
        setRegrading(true);
        setError(null);
        setErrorList([]);
        setFeedback(null);
        try {
            const res = await regradeQuestion(regradeTarget.id, {
                options: regradeOptions,
                correct_option_ids: regradeCorrectIds,
            });
            setRegradeTarget(null);
            setFeedback(
                `Regrade complete: ${res.regraded ?? 0} answer(s) regraded, ${
                    res.unchanged ?? 0
                } unchanged. Affected learners' recommendations reconciled.`,
            );
            await loadQuestions(selectedTopicId);
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setRegrading(false);
        }
    };

    const handleKlenamImport = async (e) => {
        e.preventDefault();
        if (!selectedTopicId || !importFile) return;
        setImporting(true);
        setError(null);
        setErrorList([]);
        setImportReport(null);
        try {
            const res = await importKlenamQuestions(selectedTopicId, importFile, importFormat);
            setImportReport(res);
            setImportFile(null);
            await loadQuestions(selectedTopicId);
            onTopicsChanged?.();
        } catch (err) {
            const data = err?.response?.data;
            setError(data?.message ?? parseApiError(err).message);
            setErrorList(Array.isArray(data?.errors) ? data.errors : []);
        } finally {
            setImporting(false);
        }
    };

    return (
        <div className="space-y-6">
            {/* Course topics header with + Add Topic button */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">Course Topics</h2>
                    <p className="text-xs text-slate-500">
                        Select a topic to manage its question bank, or add, edit, archive, and
                        restore topics.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={openCreateTopicModal}
                    className="rounded-md bg-indigo-600 px-3.5 py-2 text-xs font-medium text-white hover:bg-indigo-700"
                >
                    + Add topic
                </button>
            </div>

            {/* Topic selector cards */}
            {topics.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No topics in this course yet. Click <strong>+ Add topic</strong> to create one.
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    {topics.map((t) => {
                        const isSelected = Number(t.id) === Number(selectedTopicId);
                        const isArchived = t.status === 'archived';
                        return (
                            <div
                                key={t.id}
                                onClick={() => setSelectedTopicId(t.id)}
                                className={`flex cursor-pointer flex-col justify-between rounded-xl border p-4 text-left shadow-sm transition ${
                                    isSelected
                                        ? 'border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600'
                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                } ${isArchived ? 'opacity-70' : ''}`}
                            >
                                <div>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="text-base font-semibold text-slate-900">
                                                {/* The topic name is the keyboard-accessible way to select the card. */}
                                                <button
                                                    type="button"
                                                    aria-pressed={isSelected}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setSelectedTopicId(t.id);
                                                    }}
                                                    className="text-left hover:underline"
                                                >
                                                    {t.name ?? t.topic_name}
                                                </button>
                                            </h3>
                                            {isArchived && (
                                                <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                                                    Archived
                                                </span>
                                            )}
                                        </div>
                                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            Topic size: {t.topic_size} marks
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-slate-600">
                                        {t.active_questions} active{' '}
                                        {t.active_questions === 1 ? 'question' : 'questions'} in bank
                                    </p>
                                    {t.description && (
                                        <p className="mt-1.5 text-xs text-slate-600">
                                            {t.description}
                                        </p>
                                    )}
                                </div>

                                {Array.isArray(t.warnings) && t.warnings.length > 0 && (
                                    <ul className="mt-3 space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-900">
                                        {t.warnings.map((w, idx) => (
                                            <li key={idx}>⚠ {w}</li>
                                        ))}
                                    </ul>
                                )}

                                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5">
                                    <button
                                        type="button"
                                        onClick={(e) => openEditTopicModal(t, e)}
                                        className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        disabled={togglingTopicId === t.id}
                                        onClick={(e) => handleToggleTopicArchive(t, e)}
                                        className={`rounded-md border px-2.5 py-1 text-xs font-medium disabled:opacity-60 ${
                                            isArchived
                                                ? 'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                                                : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100'
                                        }`}
                                    >
                                        {togglingTopicId === t.id
                                            ? 'Saving…'
                                            : isArchived
                                              ? 'Restore'
                                              : 'Archive'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={(e) => openDeleteTopicDialog(t, e)}
                                        className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {feedback && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {feedback}
                </div>
            )}

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    <div>{error}</div>
                    {errorList.length > 0 && (
                        <ul className="mt-1.5 list-inside list-disc text-xs">
                            {errorList.map((item, idx) => (
                                <li key={idx}>{item}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {selectedTopic && (
                <div className="space-y-6">
                    {/* Header & Klenam Import bar */}
                    <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Question Bank: {selectedTopic.name ?? selectedTopic.topic_name}
                                </h2>
                                {selectedTopic.status === 'archived' && (
                                    <span className="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800">
                                        Archived
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-slate-500">
                                Topic size: <strong>{selectedTopic.topic_size} marks</strong> — total
                                marks of active questions in this topic.
                            </p>
                            {Array.isArray(selectedTopic.learning_outcomes) &&
                                selectedTopic.learning_outcomes.length > 0 && (
                                    <ul className="mt-2 list-inside list-disc text-xs text-slate-600">
                                        {selectedTopic.learning_outcomes.map((outcome, idx) => (
                                            <li key={idx}>{outcome}</li>
                                        ))}
                                    </ul>
                                )}
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={(e) => openEditTopicModal(selectedTopic, e)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Edit topic
                            </button>
                            <button
                                type="button"
                                disabled={togglingTopicId === selectedTopic.id}
                                onClick={(e) => handleToggleTopicArchive(selectedTopic, e)}
                                className={`rounded-md border px-3 py-2 text-xs font-medium disabled:opacity-60 ${
                                    selectedTopic.status === 'archived'
                                        ? 'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                                        : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100'
                                }`}
                            >
                                {selectedTopic.status === 'archived'
                                    ? 'Restore topic'
                                    : 'Archive topic'}
                            </button>
                            <button
                                type="button"
                                onClick={(e) => openDeleteTopicDialog(selectedTopic, e)}
                                className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100"
                            >
                                Delete topic
                            </button>
                            {learningSources.question_search && (
                                <button
                                    type="button"
                                    onClick={() => setShowQuizApiSearch(true)}
                                    className="rounded-md border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                >
                                    Find questions (QuizAPI)
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md bg-indigo-700 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-800"
                            >
                                + Add Question
                            </button>
                        </div>
                    </div>

                    {/* Study Resources curator for this topic (§15) */}
                    <LecturerTopicResources
                        topicId={selectedTopic.id}
                        topicName={selectedTopic.name ?? selectedTopic.topic_name}
                        videoSuggestionsEnabled={learningSources.video_suggestions}
                    />

                    {/* Klenam File Import Form (§12 POST /api/topics/{topicId}/questions/import) */}
                    <form
                        onSubmit={handleKlenamImport}
                        className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                    >
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">
                                    Import Klenam AI Questions
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Upload a Klenam JSON or CSV export to add objective questions to
                                    this topic.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <select
                                    value={importFormat}
                                    onChange={(e) => setImportFormat(e.target.value)}
                                    aria-label="Import file format"
                                    className="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs"
                                >
                                    <option value="json">JSON</option>
                                    <option value="csv">CSV</option>
                                </select>
                                <input
                                    type="file"
                                    aria-label="Question file to import (JSON or CSV)"
                                    accept=".json,.csv,application/json,text/csv"
                                    onChange={(e) => setImportFile(e.target.files?.[0] ?? null)}
                                    className="text-xs text-slate-600"
                                />
                                <button
                                    type="submit"
                                    disabled={!importFile || importing}
                                    className="rounded-md border border-indigo-300 bg-indigo-50 px-3.5 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:opacity-50"
                                >
                                    {importing ? 'Importing…' : 'Import file'}
                                </button>
                            </div>
                        </div>

                        {importReport && (
                            <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
                                <div className="font-semibold text-slate-800">
                                    Imported {importReport.imported?.length ?? 0} question(s);
                                    rejected {importReport.rejected?.length ?? 0}.
                                </div>
                                {Array.isArray(importReport.rejected) &&
                                    importReport.rejected.length > 0 && (
                                        <ul className="mt-1.5 space-y-1 text-rose-700">
                                            {importReport.rejected.map((r, idx) => (
                                                <li key={idx}>
                                                    Row {r.row}: {r.reason}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                            </div>
                        )}
                    </form>

                    {/* Questions list */}
                    {loadingQuestions ? (
                        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                            Loading questions…
                        </div>
                    ) : questions.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                            No questions in this topic yet.
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {questions.map((q) => {
                                const used = isQuestionUsed(q);
                                const correctIds = (q.answer_data?.correct_option_ids ?? []).map(
                                    String,
                                );

                                return (
                                    <article
                                        key={q.id}
                                        className={`rounded-xl border bg-white p-5 shadow-sm ${
                                            q.status === 'inactive'
                                                ? 'border-slate-200 opacity-60'
                                                : 'border-slate-200'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 font-semibold uppercase text-slate-700">
                                                    {q.question_type}
                                                </span>
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 capitalize text-slate-700">
                                                    {q.difficulty}
                                                </span>
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">
                                                    {q.max_marks} marks
                                                </span>
                                                {q.source && (
                                                    <span className="rounded-md bg-indigo-50 px-2 py-0.5 text-indigo-700">
                                                        Source: {q.source}
                                                    </span>
                                                )}
                                                {used && (
                                                    <span className="rounded-md bg-amber-50 px-2 py-0.5 font-medium text-amber-800">
                                                        Used in attempts (marks/options locked)
                                                    </span>
                                                )}
                                                {q.status === 'inactive' && (
                                                    <span className="rounded-md bg-rose-50 px-2 py-0.5 font-medium text-rose-700">
                                                        Archived (inactive)
                                                    </span>
                                                )}
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditModal(q, false)}
                                                    className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => openRegradeModal(q)}
                                                    className="rounded-md border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                                >
                                                    Regrade answer key
                                                </button>
                                                {q.status !== 'inactive' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleArchive(q.id)}
                                                        className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                                    >
                                                        Archive
                                                    </button>
                                                )}
                                            </div>
                                        </div>

                                        <p className="mt-3 text-sm font-medium text-slate-900">
                                            {q.question_text}
                                        </p>

                                        {Array.isArray(q.answer_data?.options) && (
                                            <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                                                {q.answer_data.options.map((opt) => {
                                                    const isCorrect = correctIds.includes(
                                                        String(opt.id),
                                                    );
                                                    return (
                                                        <li
                                                            key={opt.id}
                                                            className={`flex items-center justify-between rounded-lg border px-3 py-1.5 text-xs ${
                                                                isCorrect
                                                                    ? 'border-emerald-300 bg-emerald-50 font-medium text-emerald-900'
                                                                    : 'border-slate-200 bg-slate-50 text-slate-700'
                                                            }`}
                                                        >
                                                            <span>
                                                                <strong className="uppercase">
                                                                    {opt.id}.
                                                                </strong>{' '}
                                                                {opt.text}
                                                            </span>
                                                            {isCorrect && (
                                                                <span className="text-emerald-700">
                                                                    ✓ Correct
                                                                </span>
                                                            )}
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}

                                        {q.explanation && (
                                            <p className="mt-2.5 text-xs text-slate-500">
                                                <strong>Explanation:</strong> {q.explanation}
                                            </p>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

            {/* Delete Topic Confirmation & 409 Archive Fallback Modal (§13) */}
            {deleteTargetTopic && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-slate-900">
                            {deleteConflictMessage
                                ? 'Cannot Delete Topic'
                                : `Delete "${deleteTargetTopic.name ?? deleteTargetTopic.topic_name}"?`}
                        </h2>

                        {deleteConflictMessage ? (
                            <div className="mt-3 space-y-3">
                                <p
                                    role="alert"
                                    className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                                >
                                    {deleteConflictMessage}
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        disabled={deletingTopic}
                                        onClick={() => {
                                            setDeleteTargetTopic(null);
                                            setDeleteConflictMessage(null);
                                        }}
                                        className="rounded-md border border-slate-300 px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Close
                                    </button>
                                    {deleteTargetTopic.status !== 'archived' && (
                                        <button
                                            type="button"
                                            disabled={deletingTopic}
                                            onClick={handleArchiveInsteadOfDelete}
                                            className="rounded-md bg-amber-700 px-4 py-1.5 text-xs font-medium text-white hover:bg-amber-800 disabled:opacity-60"
                                        >
                                            {deletingTopic ? 'Archiving…' : 'Archive instead'}
                                        </button>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="mt-2 space-y-4">
                                <p className="text-xs text-slate-600">
                                    Are you sure you want to permanently delete{' '}
                                    <strong>
                                        {deleteTargetTopic.name ?? deleteTargetTopic.topic_name}
                                    </strong>
                                    ? Its unused questions and Canvas mappings will also be removed.
                                    If students already have results for this topic, you will be
                                    offered the option to archive it instead.
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        disabled={deletingTopic}
                                        onClick={() => setDeleteTargetTopic(null)}
                                        className="rounded-md border border-slate-300 px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        disabled={deletingTopic}
                                        onClick={handleConfirmDeleteTopic}
                                        className="rounded-md bg-rose-700 px-4 py-1.5 text-xs font-medium text-white hover:bg-rose-800 disabled:opacity-60"
                                    >
                                        {deletingTopic ? 'Deleting…' : 'Delete topic'}
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Add / Edit Topic Modal (§13) */}
            {editingTopic && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-slate-900">
                            {editingTopic === 'new' ? 'Add Topic' : 'Edit Topic'}
                        </h2>
                        <form onSubmit={handleSaveTopic} className="mt-4 space-y-4 text-sm">
                            <div>
                                <label
                                    htmlFor="topic_name"
                                    className="mb-1 block text-xs font-medium text-slate-700"
                                >
                                    Topic name
                                </label>
                                <input
                                    id="topic_name"
                                    type="text"
                                    maxLength={255}
                                    required
                                    value={topicForm.name}
                                    onChange={(e) =>
                                        setTopicForm((f) => ({ ...f, name: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-xs"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="topic_description"
                                    className="mb-1 block text-xs font-medium text-slate-700"
                                >
                                    Description (optional)
                                </label>
                                <textarea
                                    id="topic_description"
                                    rows={2}
                                    value={topicForm.description}
                                    onChange={(e) =>
                                        setTopicForm((f) => ({
                                            ...f,
                                            description: e.target.value,
                                        }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-xs"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="topic_outcomes"
                                    className="mb-1 block text-xs font-medium text-slate-700"
                                >
                                    Learning outcomes (optional, one per line)
                                </label>
                                <textarea
                                    id="topic_outcomes"
                                    rows={3}
                                    value={topicForm.learning_outcomes_text}
                                    onChange={(e) =>
                                        setTopicForm((f) => ({
                                            ...f,
                                            learning_outcomes_text: e.target.value,
                                        }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-xs"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setEditingTopic(null)}
                                    className="rounded-md border border-slate-300 px-3.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingTopic}
                                    className="rounded-md bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {savingTopic ? 'Saving…' : 'Save topic'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Create / Edit Question Modal */}
            {editingQuestion && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-900">
                                {editingQuestion === 'new' ? 'Create Question' : 'Edit Question'}
                            </h2>
                            {editingQuestion !== 'new' && (
                                <label className="flex items-center gap-1.5 text-xs text-slate-600">
                                    <input
                                        type="checkbox"
                                        checked={forceLockedUsed}
                                        onChange={(e) => setForceLockedUsed(e.target.checked)}
                                    />
                                    Question used in attempts
                                </label>
                            )}
                        </div>

                        {forceLockedUsed && editingQuestion !== 'new' && (
                            <div className="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-900">
                                This question has been used in learner attempts.{' '}
                                <strong>Maximum marks</strong>, <strong>Question type</strong>, and{' '}
                                <strong>Options</strong> are disabled. Use{' '}
                                <button
                                    type="button"
                                    onClick={() => {
                                        const q = editingQuestion;
                                        setEditingQuestion(null);
                                        openRegradeModal(q);
                                    }}
                                    className="font-semibold underline"
                                >
                                    Regrade
                                </button>{' '}
                                to correct the answer key.
                            </div>
                        )}

                        <form onSubmit={handleSaveQuestion} className="mt-4 space-y-4 text-sm">
                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-slate-700">
                                        Type
                                    </label>
                                    <select
                                        value={form.question_type}
                                        disabled={forceLockedUsed}
                                        onChange={(e) => handleTypeChange(e.target.value)}
                                        className="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs disabled:bg-slate-100"
                                    >
                                        <option value="mcq">Multiple Choice</option>
                                        <option value="true_false">True / False</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-1 block text-xs font-medium text-slate-700">
                                        Difficulty
                                    </label>
                                    <select
                                        value={form.difficulty}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, difficulty: e.target.value }))
                                        }
                                        className="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs"
                                    >
                                        <option value="easy">Easy</option>
                                        <option value="medium">Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-1 block text-xs font-medium text-slate-700">
                                        Max marks
                                    </label>
                                    <input
                                        type="number"
                                        step="0.5"
                                        min="0.5"
                                        disabled={forceLockedUsed}
                                        value={form.max_marks}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, max_marks: e.target.value }))
                                        }
                                        required
                                        className="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs disabled:bg-slate-100"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-700">
                                    Question text
                                </label>
                                <textarea
                                    rows={3}
                                    value={form.question_text}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, question_text: e.target.value }))
                                    }
                                    required
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-xs"
                                />
                            </div>

                            <div className="space-y-2">
                                <div className="text-xs font-medium text-slate-700">
                                    Options & Correct Answer
                                </div>
                                {form.options.map((opt, idx) => (
                                    <div key={opt.id} className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="correct_option"
                                            disabled={forceLockedUsed}
                                            checked={form.correct_option_ids.includes(opt.id)}
                                            onChange={() =>
                                                setForm((f) => ({
                                                    ...f,
                                                    correct_option_ids: [opt.id],
                                                }))
                                            }
                                        />
                                        <span className="w-6 text-xs font-semibold uppercase text-slate-500">
                                            {opt.id}.
                                        </span>
                                        <input
                                            type="text"
                                            disabled={
                                                forceLockedUsed ||
                                                form.question_type === 'true_false'
                                            }
                                            value={opt.text}
                                            onChange={(e) => {
                                                const next = [...form.options];
                                                next[idx] = { ...opt, text: e.target.value };
                                                setForm((f) => ({ ...f, options: next }));
                                            }}
                                            required
                                            className="flex-1 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs disabled:bg-slate-100"
                                        />
                                    </div>
                                ))}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-700">
                                    Explanation (shown to learners after submission)
                                </label>
                                <textarea
                                    rows={2}
                                    value={form.explanation}
                                    onChange={(e) =>
                                        setForm((f) => ({ ...f, explanation: e.target.value }))
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-xs"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setEditingQuestion(null)}
                                    className="rounded-md border border-slate-300 px-3.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingForm}
                                    className="rounded-md bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {savingForm ? 'Saving…' : 'Save question'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Regrade Modal (§12 POST /api/questions/{questionId}/regrade) */}
            {regradeTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-slate-900">
                            Regrade Question (Answer-Key Correction)
                        </h2>
                        <p className="mt-1 text-xs text-slate-600">
                            Regrade may update option wording and the correct option, creating a new{' '}
                            <code>auto</code> mark version (reason <code>regrade</code>) where a
                            learner’s score changes and reconciling recommendations. Options cannot
                            be added, removed, or reordered.
                        </p>

                        <form onSubmit={handleRegradeSubmit} className="mt-4 space-y-3">
                            {regradeOptions.map((opt, idx) => (
                                <div key={opt.id} className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="regrade_correct"
                                        checked={regradeCorrectIds.includes(opt.id)}
                                        onChange={() => setRegradeCorrectIds([opt.id])}
                                    />
                                    <span className="w-8 text-xs font-semibold uppercase text-slate-500">
                                        {opt.id}.
                                    </span>
                                    <input
                                        type="text"
                                        value={opt.text}
                                        onChange={(e) => {
                                            const next = [...regradeOptions];
                                            next[idx] = { ...opt, text: e.target.value };
                                            setRegradeOptions(next);
                                        }}
                                        required
                                        className="flex-1 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs"
                                    />
                                </div>
                            ))}

                            <div className="flex justify-end gap-2 pt-3">
                                <button
                                    type="button"
                                    onClick={() => setRegradeTarget(null)}
                                    className="rounded-md border border-slate-300 px-3.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={regrading}
                                    className="rounded-md bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {regrading ? 'Regrading…' : 'Save & Regrade'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            {/* Find questions online (QuizAPI) modal (§15) */}
            {showQuizApiSearch && selectedTopic && (
                <LecturerQuizApiSearch
                    topicId={selectedTopic.id}
                    topicName={selectedTopic.name ?? selectedTopic.topic_name}
                    onImported={async () => {
                        await loadQuestions(selectedTopic.id);
                        onTopicsChanged?.();
                    }}
                    onClose={() => setShowQuizApiSearch(false)}
                />
            )}
        </div>
    );
}
