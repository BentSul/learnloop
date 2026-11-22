import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { fetchLecturerCourseTopics, fetchMyCourses } from '../api/adaptive';
import { parseApiError } from '../api/client';
import LecturerCanvasMapping from '../components/LecturerCanvasMapping';
import LecturerClassOverview from '../components/LecturerClassOverview';
import LecturerQuestionBank from '../components/LecturerQuestionBank';

const TABS = [
    { key: 'questions', label: 'Topics & Question Bank' },
    { key: 'canvas', label: 'Canvas Mappings' },
    { key: 'overview', label: 'Class Overview' },
];

export default function LecturerHub() {
    const { courseId } = useParams();
    const navigate = useNavigate();

    const [courses, setCourses] = useState([]);
    const [topics, setTopics] = useState([]);
    const [activeTab, setActiveTab] = useState('questions');
    const [loadingCourses, setLoadingCourses] = useState(true);
    const [loadingTopics, setLoadingTopics] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        setLoadingCourses(true);
        fetchMyCourses()
            .then((res) => {
                if (!active) return;
                const list = Array.isArray(res) ? res : res?.data ?? [];
                setCourses(list);
                if (!courseId && list.length > 0) {
                    navigate(`/lecturer/courses/${list[0].id}`, { replace: true });
                }
            })
            .catch((err) => {
                if (!active) return;
                setError(parseApiError(err).message);
            })
            .finally(() => {
                if (active) setLoadingCourses(false);
            });
        return () => {
            active = false;
        };
    }, [courseId, navigate]);

    const loadTopics = useCallback(async (cid) => {
        if (!cid) return;
        setLoadingTopics(true);
        setError(null);
        try {
            const res = await fetchLecturerCourseTopics(cid);
            setTopics(Array.isArray(res) ? res : res?.data ?? []);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoadingTopics(false);
        }
    }, []);

    useEffect(() => {
        if (courseId) {
            loadTopics(courseId);
        }
    }, [courseId, loadTopics]);

    const currentCourse = courses.find((c) => String(c.id) === String(courseId)) ?? null;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <span className="inline-block rounded-full bg-indigo-50 px-3 py-0.5 text-xs font-semibold text-indigo-700">
                        Lecturer Tools
                    </span>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">
                        {currentCourse
                            ? `${currentCourse.code}: ${currentCourse.name}`
                            : 'Course Management'}
                    </h1>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Manage question banks, Klenam AI imports, Regrade, Canvas assignment
                        mappings, and class performance overview.
                    </p>
                </div>

                {courses.length > 1 && (
                    <div className="flex items-center gap-2 text-xs">
                        <label htmlFor="lecturer-course-select" className="font-medium text-slate-600">
                            Course:
                        </label>
                        <select
                            id="lecturer-course-select"
                            value={courseId ?? ''}
                            onChange={(e) => navigate(`/lecturer/courses/${e.target.value}`)}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 shadow-sm"
                        >
                            {courses.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.code} — {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </div>

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {/* Section Tabs */}
            <div className="flex flex-wrap gap-1.5 border-b border-slate-200 pb-3">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => setActiveTab(t.key)}
                        className={`rounded-md px-3.5 py-2 text-xs font-semibold transition ${
                            activeTab === t.key
                                ? 'bg-indigo-600 text-white'
                                : 'bg-white text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {loadingCourses || loadingTopics ? (
                <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                    Loading lecturer tools…
                </div>
            ) : !courseId ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No Canvas courses found where you have a teacher role.
                </div>
            ) : (
                <>
                    {activeTab === 'questions' && (
                        <LecturerQuestionBank
                            topics={topics}
                            onTopicsChanged={() => loadTopics(courseId)}
                        />
                    )}
                    {activeTab === 'canvas' && (
                        <LecturerCanvasMapping courseId={courseId} topics={topics} />
                    )}
                    {activeTab === 'overview' && <LecturerClassOverview courseId={courseId} />}
                </>
            )}
        </div>
    );
}
