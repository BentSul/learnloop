import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { fetchAttempt, saveAttemptAnswer, submitAttempt } from '../api/adaptive';
import { parseApiError } from '../api/client';
import SubmitAttemptDialog from '../components/SubmitAttemptDialog';

/**
 * Practice & Revision Quiz Player (AGENTS.md §9, spec §6.4–§6.8, v0.8 §5).
 *
 * - Renders frozen questions and options in the order returned by LearnerAttemptView.
 * - Autosaves each option selection immediately via PUT /api/me/attempts/{id}/answers/{qId}.
 * - Shows SubmitAttemptDialog on 422 {unanswered, requires_confirmation: true}.
 * - After submission, shows score per question, correct/incorrect and explanation (never answer keys).
 */
export default function QuizPlayer() {
    const { attemptId } = useParams();
    const location = useLocation();

    const [attempt, setAttempt] = useState(() => location.state?.attempt ?? null);
    const [selectedByQuestion, setSelectedByQuestion] = useState({});
    const [saveStatus, setSaveStatus] = useState('idle'); // idle | saving | saved | error
    const [loading, setLoading] = useState(!location.state?.attempt);
    const [submitting, setSubmitting] = useState(false);
    const [unansweredPromptCount, setUnansweredPromptCount] = useState(0);
    const [submitResult, setSubmitResult] = useState(null);
    const [error, setError] = useState(null);

    const hydrateSelections = useCallback((attemptData) => {
        const map = {};
        for (const q of attemptData?.questions ?? []) {
            map[q.question_id] = q.response?.option_ids ?? [];
        }
        setSelectedByQuestion(map);
    }, []);

    useEffect(() => {
        if (location.state?.attempt) {
            hydrateSelections(location.state.attempt);
            return;
        }
        let active = true;
        setLoading(true);
        fetchAttempt(attemptId)
            .then((data) => {
                if (!active) return;
                setAttempt(data);
                hydrateSelections(data);
            })
            .catch((err) => {
                if (!active) return;
                setError(parseApiError(err).message);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [attemptId, location.state, hydrateSelections]);

    const handleOptionSelect = async (questionId, optionId) => {
        if (!attempt || attempt.status !== 'in_progress' || submitResult) return;

        const nextOptionIds = [String(optionId)];
        setSelectedByQuestion((prev) => ({ ...prev, [questionId]: nextOptionIds }));
        setSaveStatus('saving');
        setError(null);

        try {
            await saveAttemptAnswer(attempt.attempt_id, questionId, nextOptionIds);
            setSaveStatus('saved');
        } catch (err) {
            setSaveStatus('error');
            setError(parseApiError(err).message);
        }
    };

    const handleClearSelection = async (questionId) => {
        if (!attempt || attempt.status !== 'in_progress' || submitResult) return;

        setSelectedByQuestion((prev) => ({ ...prev, [questionId]: [] }));
        setSaveStatus('saving');
        setError(null);

        try {
            await saveAttemptAnswer(attempt.attempt_id, questionId, []);
            setSaveStatus('saved');
        } catch (err) {
            setSaveStatus('error');
            setError(parseApiError(err).message);
        }
    };

    const doSubmit = async (confirmUnanswered) => {
        setSubmitting(true);
        setError(null);
        try {
            const result = await submitAttempt(attempt.attempt_id, confirmUnanswered);
            setUnansweredPromptCount(0);
            setSubmitResult(result);
        } catch (err) {
            const data = err?.response?.data;
            if (err?.response?.status === 422 && data?.requires_confirmation) {
                setUnansweredPromptCount(Number(data.unanswered) || 1);
                return;
            }
            setError(parseApiError(err).message);
        } finally {
            setSubmitting(false);
        }
    };

    if (loading) {
        return (
            <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Loading quiz attempt…
            </div>
        );
    }

    if (!attempt) {
        return (
            <div className="space-y-4">
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    {error ?? 'Attempt not found.'}
                </div>
                <Link to="/dashboard" className="text-sm font-medium text-indigo-600 hover:underline">
                    ← Return to dashboard
                </Link>
            </div>
        );
    }

    const questions = attempt.questions ?? [];
    const answeredCount = questions.filter(
        (q) => (selectedByQuestion[q.question_id] ?? []).length > 0,
    ).length;

    // Post-submission result view ($result->toLearnerArray())
    if (submitResult) {
        const resultByQuestionId = {};
        for (const rq of submitResult.questions ?? []) {
            resultByQuestionId[rq.question_id] = rq;
        }

        return (
            <div className="space-y-6">
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <span className="inline-block rounded-full bg-emerald-100 px-3 py-0.5 text-xs font-semibold text-emerald-800">
                                Quiz Submitted
                            </span>
                            <h1 className="mt-2 text-2xl font-bold text-slate-900">Your Results</h1>
                            <p className="mt-1 text-sm text-slate-700">
                                Your answers have been recorded as evidence and your recommendations updated.
                            </p>
                        </div>

                        <div className="text-right">
                            {submitResult.performance_display !== null && (
                                <div className="text-3xl font-bold text-slate-900">
                                    {submitResult.performance_display}%
                                </div>
                            )}
                            <div className="text-sm font-medium text-slate-600">
                                {submitResult.earned_marks} / {submitResult.max_marks} marks
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <Link
                            to="/dashboard"
                            className="rounded-md bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700"
                        >
                            Back to dashboard
                        </Link>
                        <Link
                            to="/recommendations"
                            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                            View recommendations
                        </Link>
                    </div>
                </div>

                <div className="space-y-4">
                    {questions.map((q) => {
                        const res = resultByQuestionId[q.question_id];
                        const chosenIds = selectedByQuestion[q.question_id] ?? [];
                        const chosenOptions = (q.options ?? []).filter((o) =>
                            chosenIds.includes(String(o.id)),
                        );

                        return (
                            <article
                                key={q.question_id}
                                className={`rounded-xl border bg-white p-5 shadow-sm ${
                                    res?.correct ? 'border-emerald-200' : 'border-rose-200'
                                }`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Question {q.position}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                                                res?.correct
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : 'bg-rose-100 text-rose-800'
                                            }`}
                                        >
                                            {res?.correct ? 'Correct' : 'Incorrect'}
                                        </span>
                                        <span className="text-xs font-medium text-slate-600">
                                            {res?.earned_marks ?? 0} / {res?.max_marks ?? q.max_marks} marks
                                        </span>
                                    </div>
                                </div>

                                <p className="mt-2 text-sm font-medium text-slate-900">{q.text}</p>

                                <div className="mt-3 text-xs text-slate-600">
                                    <strong>Your answer:</strong>{' '}
                                    {chosenOptions.length > 0
                                        ? chosenOptions.map((o) => o.text).join(', ')
                                        : 'No answer selected (blank — 0 marks)'}
                                </div>

                                {res?.explanation && (
                                    <div className="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-700">
                                        <strong>Explanation:</strong> {res.explanation}
                                    </div>
                                )}
                            </article>
                        );
                    })}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div>
                    <Link to="/dashboard" className="text-xs font-medium text-indigo-600 hover:underline">
                        ← Save & return later
                    </Link>
                    <h1 className="mt-1 text-xl font-bold text-slate-900">
                        Practice Quiz (Attempt #{attempt.attempt_id})
                    </h1>
                    <p className="text-xs text-slate-500">
                        {answeredCount} of {questions.length} answered
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <span className="text-xs text-slate-500">
                        {saveStatus === 'saving' && 'Saving answer…'}
                        {saveStatus === 'saved' && '✓ Saved'}
                        {saveStatus === 'error' && '⚠ Save failed'}
                    </span>
                    <button
                        type="button"
                        disabled={submitting}
                        onClick={() => doSubmit(false)}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {submitting ? 'Submitting…' : 'Submit quiz'}
                    </button>
                </div>
            </div>

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            <div className="space-y-4">
                {questions.map((q) => {
                    const selected = selectedByQuestion[q.question_id] ?? [];

                    return (
                        <article
                            key={q.question_id}
                            className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <div className="flex items-center justify-between text-xs text-slate-500">
                                <span className="font-semibold uppercase tracking-wide">
                                    Question {q.position}
                                </span>
                                <span>
                                    {q.max_marks} {Number(q.max_marks) === 1 ? 'mark' : 'marks'}
                                </span>
                            </div>

                            <p className="mt-2 text-base font-medium text-slate-900">{q.text}</p>

                            <div className="mt-4 space-y-2">
                                {(q.options ?? []).map((opt) => {
                                    const isChecked = selected.includes(String(opt.id));
                                    return (
                                        <label
                                            key={opt.id}
                                            className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-2.5 text-sm transition ${
                                                isChecked
                                                    ? 'border-indigo-600 bg-indigo-50/70 text-indigo-950'
                                                    : 'border-slate-200 hover:bg-slate-50'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name={`question-${q.question_id}`}
                                                checked={isChecked}
                                                onChange={() =>
                                                    handleOptionSelect(q.question_id, opt.id)
                                                }
                                                className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span>{opt.text}</span>
                                        </label>
                                    );
                                })}
                            </div>

                            {selected.length > 0 && (
                                <div className="mt-3">
                                    <button
                                        type="button"
                                        onClick={() => handleClearSelection(q.question_id)}
                                        className="text-xs text-slate-500 hover:text-slate-800 hover:underline"
                                    >
                                        Clear selection
                                    </button>
                                </div>
                            )}
                        </article>
                    );
                })}
            </div>

            <div className="flex justify-end">
                <button
                    type="button"
                    disabled={submitting}
                    onClick={() => doSubmit(false)}
                    className="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {submitting ? 'Submitting…' : 'Submit quiz'}
                </button>
            </div>

            <SubmitAttemptDialog
                unansweredCount={unansweredPromptCount}
                onCancel={() => setUnansweredPromptCount(0)}
                onConfirm={() => doSubmit(true)}
                submitting={submitting}
            />
        </div>
    );
}
