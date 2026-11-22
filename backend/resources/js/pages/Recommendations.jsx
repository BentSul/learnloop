import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
    acceptRecommendation,
    dismissRecommendation,
    fetchRecommendations,
    postponeRecommendation,
    restoreRecommendation,
    startRevisionQuiz,
} from '../api/adaptive';
import { parseApiError } from '../api/client';
import PostponeModal from '../components/PostponeModal';
import RecommendationCard from '../components/RecommendationCard';

const SECTIONS = [
    { key: 'active', label: 'Active' },
    { key: 'accepted', label: 'Accepted' },
    { key: 'postponed', label: 'Postponed' },
    { key: 'dismissed', label: 'Dismissed (Never again)' },
    { key: 'history', label: 'History' },
];

export default function Recommendations() {
    const navigate = useNavigate();

    const [activeSection, setActiveSection] = useState('active');
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);
    const [postponeTarget, setPostponeTarget] = useState(null);
    const [feedbackMessage, setFeedbackMessage] = useState(null);
    const [error, setError] = useState(null);

    const loadSection = useCallback(async (sectionKey) => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetchRecommendations(sectionKey);
            setItems(res?.data ?? (Array.isArray(res) ? res : []));
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadSection(activeSection);
    }, [activeSection, loadSection]);

    const handleAccept = async (id) => {
        setBusyId(id);
        setError(null);
        setFeedbackMessage(null);
        try {
            await acceptRecommendation(id);
            setFeedbackMessage('Recommendation accepted. Complete practice on this topic to mark it completed.');
            await loadSection(activeSection);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handlePostponeConfirm = async (id, isoReminder) => {
        setBusyId(id);
        setError(null);
        setFeedbackMessage(null);
        try {
            await postponeRecommendation(id, isoReminder);
            setPostponeTarget(null);
            setFeedbackMessage('Reminder scheduled.');
            await loadSection(activeSection);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleDismiss = async (id) => {
        setBusyId(id);
        setError(null);
        setFeedbackMessage(null);
        try {
            await dismissRecommendation(id);
            setFeedbackMessage('Recommendation moved to Dismissed (Never again). You can restore it anytime.');
            await loadSection(activeSection);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setBusyId(null);
        }
    };

    const handleRestore = async (id) => {
        setBusyId(id);
        setError(null);
        setFeedbackMessage(null);
        try {
            const res = await restoreRecommendation(id);
            // AGENTS.md §11: restore returns {data, restored: bool, message: string};
            // when restored is false show message.
            if (res?.message) {
                setFeedbackMessage(res.message);
            }
            await loadSection(activeSection);
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

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Study Recommendations</h1>
                <p className="mt-1 text-sm text-slate-600">
                    Personalized next steps based on your current evidence, goals, help requests, and source conflicts.
                </p>
            </div>

            {/* Section Tabs */}
            <div className="flex flex-wrap gap-1.5 border-b border-slate-200 pb-3">
                {SECTIONS.map((sec) => (
                    <button
                        key={sec.key}
                        type="button"
                        onClick={() => {
                            setFeedbackMessage(null);
                            setActiveSection(sec.key);
                        }}
                        className={`rounded-md px-3.5 py-2 text-xs font-semibold transition ${
                            activeSection === sec.key
                                ? 'bg-indigo-600 text-white'
                                : 'bg-white text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {sec.label}
                    </button>
                ))}
            </div>

            {feedbackMessage && (
                <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                    {feedbackMessage}
                </div>
            )}

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {loading ? (
                <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                    Loading {activeSection} recommendations…
                </div>
            ) : items.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    No recommendations in the <strong className="capitalize">{activeSection}</strong> section.
                </div>
            ) : (
                <div className="space-y-4">
                    {items.map((rec) => (
                        <RecommendationCard
                            key={rec.id}
                            rec={rec}
                            busyId={busyId}
                            onAccept={handleAccept}
                            onOpenPostpone={setPostponeTarget}
                            onDismiss={handleDismiss}
                            onRestore={handleRestore}
                            onStartRevisionQuiz={handleStartRevision}
                        />
                    ))}
                </div>
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
