import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
    acceptRecommendation,
    dismissRecommendation,
    fetchMyCourses,
    fetchMyGoals,
    fetchRecommendationShortlist,
    postponeRecommendation,
    removeHelpRequest,
    removeMasteryGoal,
    startRevisionQuiz,
} from '../api/adaptive';
import { parseApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import PostponeModal from '../components/PostponeModal';
import RecommendationCard from '../components/RecommendationCard';

export default function Dashboard() {
    const { user } = useAuth();
    const navigate = useNavigate();

    const [courses, setCourses] = useState([]);
    const [shortlist, setShortlist] = useState([]);
    const [goals, setGoals] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [postponeTarget, setPostponeTarget] = useState(null);

    const isLecturerOrAdmin = user?.role === 'lecturer' || user?.role === 'admin';
    const isLecturerOnly = user?.role === 'lecturer';

    const loadDashboard = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [coursesRes, shortlistRes, goalsRes] = await Promise.all([
                fetchMyCourses(),
                fetchRecommendationShortlist(),
                fetchMyGoals(),
            ]);
            setCourses(Array.isArray(coursesRes) ? coursesRes : coursesRes?.data ?? []);
            setShortlist(shortlistRes?.data ?? []);
            setGoals(Array.isArray(goalsRes) ? goalsRes : goalsRes?.data ?? []);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadDashboard();
    }, [loadDashboard]);

    const handleAccept = async (id) => {
        setBusyId(id);
        setError(null);
        try {
            await acceptRecommendation(id);
            await loadDashboard();
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handlePostponeConfirm = async (id, isoReminder) => {
        setBusyId(id);
        setError(null);
        try {
            await postponeRecommendation(id, isoReminder);
            setPostponeTarget(null);
            await loadDashboard();
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleDismiss = async (id) => {
        setBusyId(id);
        setError(null);
        try {
            await dismissRecommendation(id);
            await loadDashboard();
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleStartRevision = async (topicId) => {
        setError(null);
        try {
            const attempt = await startRevisionQuiz(topicId);
            navigate(`/attempts/${attempt.attempt_id}`, { state: { attempt } });
        } catch (err) {
            setError(parseApiError(err).message);
        }
    };

    const handleRemoveGoalItem = async (goal) => {
        setError(null);
        try {
            if (goal.goal_type === 'help') {
                await removeHelpRequest(goal.topic.id);
            } else {
                await removeMasteryGoal(goal.topic.id);
            }
            await loadDashboard();
        } catch (err) {
            setError(parseApiError(err).message);
        }
    };

    return (
        <div className="space-y-8">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Welcome, {user.name}</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Track your per-topic performance, confidence, and personalized study recommendations.
                    </p>
                </div>
                {isLecturerOrAdmin && (
                    <Link
                        to="/lecturer"
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Open Lecturer Tools
                    </Link>
                )}
            </div>

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {/* Courses Section */}
            <section>
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-slate-900">Your Courses</h2>
                </div>

                {loading ? (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                        Loading your courses…
                    </div>
                ) : courses.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                        No Canvas-linked courses found for your account yet.
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {courses.map((course) => (
                            <div
                                key={course.id}
                                className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300"
                            >
                                <div>
                                    <span className="inline-block rounded-md bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700">
                                        {course.code}
                                    </span>
                                    <h3 className="mt-2 text-lg font-semibold text-slate-900">
                                        {course.name}
                                    </h3>
                                </div>
                                <div className="mt-4 flex flex-wrap items-center gap-2 pt-2">
                                    {!isLecturerOnly && (
                                        <Link
                                            to={`/courses/${course.id}`}
                                            className="rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700"
                                        >
                                            View topics & practice
                                        </Link>
                                    )}
                                    {isLecturerOrAdmin && (
                                        <Link
                                            to={`/lecturer/courses/${course.id}`}
                                            className={
                                                isLecturerOnly
                                                    ? 'rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700'
                                                    : 'rounded-md border border-slate-300 px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50'
                                            }
                                        >
                                            Manage course (Lecturer Tools)
                                        </Link>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {/* Active Goals & Help Requests */}
            {goals.length > 0 && (
                <section>
                    <h2 className="mb-3 text-lg font-semibold text-slate-900">
                        Active Mastery Goals & Help Requests
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        {goals.map((g) => (
                            <div
                                key={g.id}
                                className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium ${
                                    g.goal_type === 'help'
                                        ? 'border-rose-200 bg-rose-50 text-rose-800'
                                        : 'border-indigo-200 bg-indigo-50 text-indigo-800'
                                }`}
                            >
                                <span>
                                    {g.goal_type === 'help' ? 'Help requested:' : 'Mastery goal:'}{' '}
                                    <strong>{g.topic?.name}</strong>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => handleRemoveGoalItem(g)}
                                    title="Remove"
                                    className="ml-1 rounded-full px-1 hover:bg-black/10"
                                >
                                    ×
                                </button>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {/* Top 5 Active Recommendations Shortlist (learners) */}
            {!isLecturerOnly && (
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">
                                Recommended Next Steps (Top 5)
                            </h2>
                            <p className="text-xs text-slate-500">
                                Ordered by priority, help requests, mastery goals, and lowest performance first.
                            </p>
                        </div>
                        <Link
                            to="/recommendations"
                            className="text-sm font-medium text-indigo-600 hover:underline"
                        >
                            View all recommendations →
                        </Link>
                    </div>

                    {loading ? (
                        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                            Loading recommendations…
                        </div>
                    ) : shortlist.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                            You have no active recommendations right now. Keep up the strong work!
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {shortlist.map((rec) => (
                                <RecommendationCard
                                    key={rec.id}
                                    rec={rec}
                                    busyId={busyId}
                                    onAccept={handleAccept}
                                    onOpenPostpone={setPostponeTarget}
                                    onDismiss={handleDismiss}
                                    onStartRevisionQuiz={handleStartRevision}
                                />
                            ))}
                        </div>
                    )}
                </section>
            )}

            <PostponeModal
                recommendation={postponeTarget}
                onClose={() => setPostponeTarget(null)}
                onConfirm={handlePostponeConfirm}
                submitting={busyId !== null}
            />
        </div>
    );
}
