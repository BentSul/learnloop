import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
    addCanvasCourse,
    fetchCourseHelpRequests,
    fetchLecturerCourseTopics,
    fetchMyCourses,
    fetchTeachingCanvasCourses,
} from '../api/adaptive';
import { parseApiError } from '../api/client';
import LecturerCanvasMapping from '../components/LecturerCanvasMapping';
import LecturerClassOverview from '../components/LecturerClassOverview';
import LecturerHelpRequests from '../components/LecturerHelpRequests';
import LecturerQuestionBank from '../components/LecturerQuestionBank';

const TABS = [
    { key: 'questions', label: 'Topics & Question Bank' },
    { key: 'canvas', label: 'Canvas Mappings' },
    { key: 'overview', label: 'Class Overview' },
    { key: 'help', label: 'Help requests' },
];

export default function LecturerHub() {
    const { courseId } = useParams();
    const navigate = useNavigate();

    const [courses, setCourses] = useState([]);
    const [topics, setTopics] = useState([]);
    const [helpRequests, setHelpRequests] = useState([]);
    const [loadingHelpRequests, setLoadingHelpRequests] = useState(false);
    const [activeTab, setActiveTab] = useState('questions');
    const [loadingCourses, setLoadingCourses] = useState(true);
    const [loadingTopics, setLoadingTopics] = useState(false);
    const [error, setError] = useState(null);

    // Add a Canvas course panel state (AGENTS.md §14)
    const [showAddCoursePanel, setShowAddCoursePanel] = useState(false);
    const [canvasCourses, setCanvasCourses] = useState([]);
    const [loadingCanvasCourses, setLoadingCanvasCourses] = useState(false);
    const [canvasCoursesNotConnected, setCanvasCoursesNotConnected] = useState(false);
    const [canvasCourseError, setCanvasCourseError] = useState(null);
    const [addingCanvasCourseId, setAddingCanvasCourseId] = useState(null);

    const loadMyCourses = useCallback(async () => {
        setLoadingCourses(true);
        try {
            const res = await fetchMyCourses();
            const list = Array.isArray(res) ? res : res?.data ?? [];
            setCourses(list);
            return list;
        } catch (err) {
            setError(parseApiError(err).message);
            return [];
        } finally {
            setLoadingCourses(false);
        }
    }, []);

    const loadCanvasTeachingCourses = useCallback(async () => {
        setLoadingCanvasCourses(true);
        setCanvasCourseError(null);
        setCanvasCoursesNotConnected(false);
        try {
            const res = await fetchTeachingCanvasCourses();
            setCanvasCourses(res?.data ?? (Array.isArray(res) ? res : []));
        } catch (err) {
            if (err?.response?.status === 409) {
                setCanvasCoursesNotConnected(true);
            }
            setCanvasCourseError(parseApiError(err).message);
        } finally {
            setLoadingCanvasCourses(false);
        }
    }, []);

    useEffect(() => {
        let active = true;
        loadMyCourses().then((list) => {
            if (!active) return;
            if (!courseId && list.length > 0) {
                navigate(`/lecturer/courses/${list[0].id}`, { replace: true });
            } else if (list.length === 0) {
                setShowAddCoursePanel(true);
                loadCanvasTeachingCourses();
            }
        });
        return () => {
            active = false;
        };
    }, [courseId, navigate, loadMyCourses, loadCanvasTeachingCourses]);

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

    const loadHelpRequests = useCallback(async (cid) => {
        if (!cid) return;
        setLoadingHelpRequests(true);
        try {
            const res = await fetchCourseHelpRequests(cid);
            setHelpRequests(res?.data ?? (Array.isArray(res) ? res : []));
        } catch {
            setHelpRequests([]);
        } finally {
            setLoadingHelpRequests(false);
        }
    }, []);

    useEffect(() => {
        if (courseId) {
            loadTopics(courseId);
            loadHelpRequests(courseId);
        }
    }, [courseId, loadTopics, loadHelpRequests]);

    const handleToggleAddCoursePanel = () => {
        const next = !showAddCoursePanel;
        setShowAddCoursePanel(next);
        if (next) {
            loadCanvasTeachingCourses();
        }
    };

    const handleAddCanvasCourse = async (canvasCourseId) => {
        setAddingCanvasCourseId(canvasCourseId);
        setCanvasCourseError(null);
        try {
            const created = await addCanvasCourse({
                canvas_course_id: String(canvasCourseId),
            });
            await Promise.all([loadMyCourses(), loadCanvasTeachingCourses()]);
            if (created?.id) {
                setShowAddCoursePanel(false);
                navigate(`/lecturer/courses/${created.id}`);
            }
        } catch (err) {
            setCanvasCourseError(parseApiError(err).message);
        } finally {
            setAddingCanvasCourseId(null);
        }
    };

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

                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {courses.length > 1 && (
                        <div className="flex items-center gap-2">
                            <label
                                htmlFor="lecturer-course-select"
                                className="font-medium text-slate-600"
                            >
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
                    <button
                        type="button"
                        onClick={handleToggleAddCoursePanel}
                        className="rounded-md border border-indigo-300 bg-indigo-50 px-3.5 py-2 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                    >
                        {showAddCoursePanel ? 'Hide Canvas courses' : '+ Add a Canvas course'}
                    </button>
                </div>
            </div>

            {/* Add a Canvas course panel (AGENTS.md §14) */}
            {showAddCoursePanel && (
                <section className="rounded-xl border border-indigo-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">
                                Add a Canvas course
                            </h2>
                            <p className="text-xs text-slate-500">
                                Select a Canvas course you teach to add it to LearnLoop. Students in
                                that course will see it automatically.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={loadCanvasTeachingCourses}
                            disabled={loadingCanvasCourses}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                        >
                            {loadingCanvasCourses ? 'Refreshing…' : '↻ Refresh list'}
                        </button>
                    </div>

                    {canvasCourseError && (
                        <div
                            role="alert"
                            className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-700"
                        >
                            <span>{canvasCourseError}</span>
                            {canvasCoursesNotConnected && (
                                <Link
                                    to="/canvas"
                                    className="ml-2 font-semibold underline hover:text-rose-900"
                                >
                                    Go to Canvas connection →
                                </Link>
                            )}
                        </div>
                    )}

                    {loadingCanvasCourses ? (
                        <p className="mt-4 text-xs text-slate-500">
                            Loading your Canvas courses…
                        </p>
                    ) : canvasCourses.length === 0 ? (
                        !canvasCourseError && (
                            <p className="mt-4 text-xs text-slate-500">
                                No Canvas courses found where you are enrolled as a teacher.
                            </p>
                        )
                    ) : (
                        <ul className="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            {canvasCourses.map((cc) => {
                                const alreadyAddedId = cc.learnloop_course_id;
                                const isAdding =
                                    String(addingCanvasCourseId) === String(cc.canvas_course_id);

                                return (
                                    <li
                                        key={cc.canvas_course_id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {cc.course_code && (
                                                    <span className="rounded bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                                                        {cc.course_code}
                                                    </span>
                                                )}
                                                <span className="text-sm font-medium text-slate-900">
                                                    {cc.name}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-slate-500">
                                                Canvas course ID: {cc.canvas_course_id}
                                            </p>
                                        </div>

                                        <div>
                                            {alreadyAddedId ? (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setShowAddCoursePanel(false);
                                                        navigate(
                                                            `/lecturer/courses/${alreadyAddedId}`,
                                                        );
                                                    }}
                                                    className="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-1.5 font-medium text-emerald-800 hover:bg-emerald-100"
                                                >
                                                    Already added → Open
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    disabled={addingCanvasCourseId !== null}
                                                    onClick={() =>
                                                        handleAddCanvasCourse(cc.canvas_course_id)
                                                    }
                                                    className="rounded-md bg-indigo-600 px-3.5 py-1.5 font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                                >
                                                    {isAdding ? 'Adding…' : 'Add'}
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            )}

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {/* Section Tabs */}
            <div className="flex flex-wrap gap-1.5 border-b border-slate-200 pb-3">
                {TABS.map((t) => {
                    const isActive = activeTab === t.key;
                    const count = t.key === 'help' ? helpRequests.length : null;
                    return (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setActiveTab(t.key)}
                            className={`inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 text-xs font-semibold transition ${
                                isActive
                                    ? 'bg-indigo-700 text-white'
                                    : 'bg-white text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            <span>{t.label}</span>
                            {count !== null && (
                                <span
                                    className={`rounded-full px-1.5 py-0.2 text-[11px] font-bold ${
                                        isActive
                                            ? 'bg-white/20 text-white'
                                            : count > 0
                                              ? 'bg-rose-100 text-rose-800'
                                              : 'bg-slate-100 text-slate-600'
                                    }`}
                                >
                                    {count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {loadingCourses || loadingTopics ? (
                <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                    Loading lecturer tools…
                </div>
            ) : !courseId ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No LearnLoop courses selected yet. Use <strong>Add a Canvas course</strong>{' '}
                    above to link a Canvas course you teach.
                </div>
            ) : (
                <>
                    {activeTab === 'questions' && (
                        <LecturerQuestionBank
                            courseId={courseId}
                            topics={topics}
                            onTopicsChanged={() => loadTopics(courseId)}
                        />
                    )}
                    {activeTab === 'canvas' && (
                        <LecturerCanvasMapping
                            courseId={courseId}
                            topics={topics}
                            onOpenTopicsTab={() => setActiveTab('questions')}
                        />
                    )}
                    {activeTab === 'overview' && <LecturerClassOverview courseId={courseId} />}
                    {activeTab === 'help' && (
                        <LecturerHelpRequests
                            helpRequests={helpRequests}
                            loading={loadingHelpRequests}
                            onChanged={() => loadHelpRequests(courseId)}
                        />
                    )}
                </>
            )}
        </div>
    );
}
