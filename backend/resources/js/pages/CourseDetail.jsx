import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
    addHelpRequest,
    addMasteryGoal,
    fetchCoursePractice,
    fetchCourseTopics,
    fetchDiagnosticPrompt,
    fetchMyCourses,
    fetchMyGoals,
    removeHelpRequest,
    removeMasteryGoal,
    startAssessmentAttempt,
    startRevisionQuiz,
    syncCourseNow,
} from '../api/adaptive';
import { parseApiError } from '../api/client';
import TopicPerformanceCard from '../components/TopicPerformanceCard';

export default function CourseDetail() {
    const { courseId } = useParams();
    const navigate = useNavigate();

    const [course, setCourse] = useState(null);
    const [topics, setTopics] = useState([]);
    const [practiceSets, setPracticeSets] = useState([]);
    const [goals, setGoals] = useState([]);
    const [showDiagnosticPrompt, setShowDiagnosticPrompt] = useState(false);

    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [syncSummary, setSyncSummary] = useState(null);
    const [busyAction, setBusyAction] = useState(null);
    const [error, setError] = useState(null);
    const [needsCanvasReconnect, setNeedsCanvasReconnect] = useState(false);

    const loadCourseData = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [coursesRes, topicsRes, practiceRes, diagRes, goalsRes] = await Promise.all([
                fetchMyCourses(),
                fetchCourseTopics(courseId),
                fetchCoursePractice(courseId),
                fetchDiagnosticPrompt(courseId),
                fetchMyGoals(),
            ]);

            const allCourses = Array.isArray(coursesRes) ? coursesRes : coursesRes?.data ?? [];
            setCourse(allCourses.find((c) => String(c.id) === String(courseId)) ?? null);
            setTopics(topicsRes?.data ?? (Array.isArray(topicsRes) ? topicsRes : []));
            setPracticeSets(Array.isArray(practiceRes) ? practiceRes : practiceRes?.data ?? []);
            setShowDiagnosticPrompt(Boolean(diagRes?.show));
            setGoals(Array.isArray(goalsRes) ? goalsRes : goalsRes?.data ?? []);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [courseId]);

    useEffect(() => {
        loadCourseData();
    }, [loadCourseData]);

    const handleSyncNow = async () => {
        setSyncing(true);
        setError(null);
        setNeedsCanvasReconnect(false);
        setSyncSummary(null);
        try {
            const result = await syncCourseNow(courseId);
            setSyncSummary(result);
            await loadCourseData();
        } catch (err) {
            setError(parseApiError(err).message);
            if (err?.response?.status === 409) {
                setNeedsCanvasReconnect(true);
            }
        } finally {
            setSyncing(false);
        }
    };

    const handleToggleGoal = async (topicId, currentlyActive) => {
        setBusyAction(`goal-${topicId}`);
        setError(null);
        try {
            if (currentlyActive) {
                await removeMasteryGoal(topicId);
            } else {
                await addMasteryGoal(topicId);
            }
            const [goalsRes, diagRes] = await Promise.all([
                fetchMyGoals(),
                fetchDiagnosticPrompt(courseId),
            ]);
            setGoals(Array.isArray(goalsRes) ? goalsRes : goalsRes?.data ?? []);
            setShowDiagnosticPrompt(Boolean(diagRes?.show));
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyAction(null);
        }
    };

    const handleToggleHelp = async (topicId, currentlyActive) => {
        setBusyAction(`help-${topicId}`);
        setError(null);
        try {
            if (currentlyActive) {
                await removeHelpRequest(topicId);
            } else {
                await addHelpRequest(topicId);
            }
            const [goalsRes, diagRes] = await Promise.all([
                fetchMyGoals(),
                fetchDiagnosticPrompt(courseId),
            ]);
            setGoals(Array.isArray(goalsRes) ? goalsRes : goalsRes?.data ?? []);
            setShowDiagnosticPrompt(Boolean(diagRes?.show));
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyAction(null);
        }
    };

    const handleStartRevisionQuiz = async (topicId) => {
        setBusyAction(`revision-${topicId}`);
        setError(null);
        try {
            const attempt = await startRevisionQuiz(topicId);
            navigate(`/attempts/${attempt.attempt_id}`, { state: { attempt } });
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyAction(null);
        }
    };

    const handleStartPracticeSet = async (assessmentId) => {
        setBusyAction(`practice-${assessmentId}`);
        setError(null);
        try {
            const attempt = await startAssessmentAttempt(assessmentId);
            navigate(`/attempts/${attempt.attempt_id}`, { state: { attempt } });
        } catch (err) {
            // AGENTS.md §9: 409 attempt already in progress includes `attempt_id`
            const existingAttemptId = err?.response?.data?.attempt_id;
            if (err?.response?.status === 409 && existingAttemptId) {
                navigate(`/attempts/${existingAttemptId}`);
                return;
            }
            setError(parseApiError(err).message);
        } finally {
            setBusyAction(null);
        }
    };

    const hasGoalForTopic = (topicId, type) =>
        goals.some((g) => Number(g.topic?.id) === Number(topicId) && g.goal_type === type);

    return (
        <div className="space-y-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link to="/dashboard" className="text-xs font-medium text-indigo-600 hover:underline">
                        ← Back to dashboard
                    </Link>
                    <div className="mt-1 flex items-center gap-2.5">
                        {course?.code && (
                            <span className="rounded-md bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700">
                                {course.code}
                            </span>
                        )}
                        <h1 className="text-2xl font-bold text-slate-900">
                            {course?.name ?? `Course #${courseId}`}
                        </h1>
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                        Per-topic performance, evidence coverage, confidence, and practice quizzes.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={handleSyncNow}
                    disabled={syncing}
                    className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-60"
                >
                    {syncing ? 'Syncing with Canvas…' : '↻ Sync Canvas now'}
                </button>
            </div>

            {syncSummary && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-900">
                    <div>
                        <strong>Canvas sync complete:</strong> {syncSummary.created ?? 0} new/updated ·{' '}
                        {syncSummary.unchanged ?? 0} unchanged
                        {syncSummary.skipped_unmapped !== undefined ||
                        syncSummary.skipped_no_score !== undefined ? (
                            <>
                                {' '}
                                · {syncSummary.skipped_unmapped ?? 0} not linked to a topic yet ·{' '}
                                {syncSummary.skipped_no_score ?? 0} not graded or released yet
                            </>
                        ) : (
                            <> · {syncSummary.skipped ?? 0} skipped</>
                        )}
                        .
                    </div>
                    {(Array.isArray(syncSummary.flagged)
                        ? syncSummary.flagged.length > 0
                        : syncSummary.flagged > 0) && (
                        <div className="mt-1 text-amber-800">
                            Some Canvas work couldn&apos;t be counted yet; your lecturer has been
                            notified.
                        </div>
                    )}
                </div>
            )}

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
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
            )}

            {/* Course-level diagnostic quiz prompt (spec §10.3, AGENTS.md §11) */}
            {showDiagnosticPrompt && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-950">
                    <div>
                        <strong className="font-semibold">Take a diagnostic quiz:</strong> One or
                        more topics in this course have not been assessed yet. Start a practice or
                        revision quiz below to establish your baseline.
                    </div>
                </div>
            )}

            {/* Topic Performance Section */}
            <section className="space-y-4">
                <h2 className="text-lg font-semibold text-slate-900">Topic Performance</h2>

                {loading ? (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                        Loading topic performance…
                    </div>
                ) : topics.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                        No active topics in this course.
                    </div>
                ) : (
                    <div className="space-y-4">
                        {topics.map((topic) => (
                            <TopicPerformanceCard
                                key={topic.topic_id}
                                topic={topic}
                                hasMasteryGoal={hasGoalForTopic(topic.topic_id, 'mastery')}
                                hasHelpRequest={hasGoalForTopic(topic.topic_id, 'help')}
                                onToggleGoal={handleToggleGoal}
                                onToggleHelp={handleToggleHelp}
                                onStartRevisionQuiz={handleStartRevisionQuiz}
                                busyAction={busyAction}
                            />
                        ))}
                    </div>
                )}
            </section>

            {/* Open Practice Quizzes Section (§10 GET /api/me/courses/{courseId}/practice) */}
            <section className="space-y-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">Course Practice Sets</h2>
                    <p className="text-xs text-slate-500">
                        Lecturer-created objective practice quizzes for this course.
                    </p>
                </div>

                {loading ? (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                        Loading practice sets…
                    </div>
                ) : practiceSets.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                        No practice sets are open for this course yet. You can still generate a
                        revision quiz on any topic above.
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {practiceSets.map((quiz) => (
                            <div
                                key={quiz.id}
                                className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                            >
                                <div>
                                    {quiz.topic && (
                                        <span className="inline-block rounded-md bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                            {typeof quiz.topic === 'object'
                                                ? quiz.topic.name
                                                : quiz.topic}
                                        </span>
                                    )}
                                    <h3 className="mt-2 text-base font-semibold text-slate-900">
                                        {quiz.title}
                                    </h3>
                                </div>

                                <div className="mt-4">
                                    <button
                                        type="button"
                                        disabled={busyAction !== null}
                                        onClick={() => handleStartPracticeSet(quiz.id)}
                                        className="rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                    >
                                        {busyAction === `practice-${quiz.id}`
                                            ? 'Opening quiz…'
                                            : 'Start practice quiz'}
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}
