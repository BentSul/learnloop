import { useCallback, useEffect, useState } from 'react';
import {
    archiveQuestion,
    createTopicQuestion,
    fetchTopicQuestions,
    importKlenamQuestions,
    regradeQuestion,
    updateQuestion,
} from '../api/adaptive';
import { parseApiError } from '../api/client';

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

export default function LecturerQuestionBank({ topics, onTopicsChanged }) {
    const [selectedTopicId, setSelectedTopicId] = useState(() => topics[0]?.id ?? null);
    const [questions, setQuestions] = useState([]);
    const [loadingQuestions, setLoadingQuestions] = useState(false);
    const [error, setError] = useState(null);
    const [errorList, setErrorList] = useState([]);
    const [feedback, setFeedback] = useState(null);

    // Create / Edit modal state
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

    useEffect(() => {
        if (!selectedTopicId && topics.length > 0) {
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
                // Once a question has been used, max_marks, question_type and options are disabled
                // and omitted from the PATCH body (AGENTS.md §12, QuestionBankService::update).
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
            // If backend reported that the question has been used, lock those fields and offer Regrade
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
            {/* Topic selector cards with lecturer-only topic_size, active_questions & warnings (§3.5, §3.6, T2) */}
            <div className="grid gap-4 sm:grid-cols-2">
                {topics.map((t) => {
                    const isSelected = Number(t.id) === Number(selectedTopicId);
                    return (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => setSelectedTopicId(t.id)}
                            className={`flex flex-col justify-between rounded-xl border p-4 text-left shadow-sm transition ${
                                isSelected
                                    ? 'border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600'
                                    : 'border-slate-200 bg-white hover:border-slate-300'
                            }`}
                        >
                            <div>
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-base font-semibold text-slate-900">
                                        {t.name ?? t.topic_name}
                                    </h3>
                                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                        Topic size: {t.topic_size} marks
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    {t.active_questions} active{' '}
                                    {t.active_questions === 1 ? 'question' : 'questions'} in bank
                                </p>
                            </div>

                            {Array.isArray(t.warnings) && t.warnings.length > 0 && (
                                <ul className="mt-3 space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-900">
                                    {t.warnings.map((w, idx) => (
                                        <li key={idx}>⚠ {w}</li>
                                    ))}
                                </ul>
                            )}
                        </button>
                    );
                })}
            </div>

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
                            <h2 className="text-lg font-semibold text-slate-900">
                                Question Bank: {selectedTopic.name ?? selectedTopic.topic_name}
                            </h2>
                            <p className="text-xs text-slate-500">
                                Topic size: <strong>{selectedTopic.topic_size} marks</strong> (lecturer-only metric, never coverage denominator — T2).
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={openCreateModal}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700"
                        >
                            + Add Question
                        </button>
                    </div>

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
                                    Upload a Klenam JSON or CSV export to add objective questions to this topic.
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
                                    Imported {importReport.imported?.length ?? 0} question(s); rejected{' '}
                                    {importReport.rejected?.length ?? 0}.
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
        </div>
    );
}
