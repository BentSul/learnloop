import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchLearnerDetail } from '../api/adaptive';
import { parseApiError } from '../api/client';
import TopicPerformanceCard from '../components/TopicPerformanceCard';

/**
 * Lecturer view of one learner's full per-topic results in a course
 * (AGENTS.md §12, GET /api/courses/{courseId}/learners/{learnerId} → {learner, topics}).
 * Each topic item has the exact §13.4 shape and is rendered read-only via TopicPerformanceCard.
 */
export default function LecturerLearnerDetail() {
    const { courseId, learnerId } = useParams();

    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const loadDetail = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetchLearnerDetail(courseId, learnerId);
            setDetail(res);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [courseId, learnerId]);

    useEffect(() => {
        loadDetail();
    }, [loadDetail]);

    return (
        <div className="space-y-6">
            <div>
                <Link
                    to={`/lecturer/courses/${courseId}`}
                    className="text-xs font-medium text-indigo-600 hover:underline"
                >
                    ← Back to lecturer course tools
                </Link>
                <h1 className="mt-1 text-2xl font-bold text-slate-900">
                    {detail?.learner?.name
                        ? `Learner Detail: ${detail.learner.name}`
                        : `Learner #${learnerId}`}
                </h1>
                <p className="mt-0.5 text-xs text-slate-500">
                    Read-only lecturer view of this learner’s topic performance, confidence reasons,
                    and source conflict details.
                </p>
            </div>

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {loading ? (
                <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                    Loading learner topic performance…
                </div>
            ) : !detail?.topics || detail.topics.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No active topics found for this learner.
                </div>
            ) : (
                <div className="space-y-4">
                    {detail.topics.map((topic) => (
                        <TopicPerformanceCard key={topic.topic_id} topic={topic} readOnly />
                    ))}
                </div>
            )}
        </div>
    );
}
