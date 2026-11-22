/**
 * Shown when POST /api/me/attempts/{attemptId}/submit returns 422
 * {"unanswered": n, "requires_confirmation": true} (AGENTS.md §9, spec §6.4).
 */
export default function SubmitAttemptDialog({ unansweredCount, onCancel, onConfirm, submitting }) {
    if (!unansweredCount) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 className="text-lg font-semibold text-slate-900">
                    Submit with unanswered questions?
                </h2>
                <p className="mt-2 text-sm text-slate-600">
                    You have <strong>{unansweredCount}</strong> unanswered{' '}
                    {unansweredCount === 1 ? 'question' : 'questions'}. Unanswered questions are
                    marked as <strong>0 marks</strong> and will replace any earlier result for those
                    questions.
                </p>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={submitting}
                        className="rounded-md border border-slate-300 px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Go back to quiz
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={submitting}
                        className="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-60"
                    >
                        {submitting ? 'Submitting…' : 'Submit anyway'}
                    </button>
                </div>
            </div>
        </div>
    );
}
