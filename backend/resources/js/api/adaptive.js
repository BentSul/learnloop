import client from './client';
import { mockStore } from './mockData';

/**
 * Checks if an Axios 404 is because the Laravel route has not been registered yet
 * ("The route api/... could not be found."). Domain 404s (e.g. "Not found." from
 * AttemptNotFound or LecturerException) are rethrown so the UI shows real errors.
 */
function isUnmergedRouteError(error) {
    if (error?.response?.status !== 404) return false;
    const msg = String(error?.response?.data?.message ?? '');
    return msg.includes('could not be found') || msg === '';
}

async function callWithMockFallback(requestFn, fallbackFn) {
    try {
        return await requestFn();
    } catch (error) {
        if (isUnmergedRouteError(error)) {
            mockStore.markUsed();
            return fallbackFn();
        }
        throw error;
    }
}

// ============================================================================
// §10 Courses, topic performance and Canvas sync
// ============================================================================

export const fetchMyCourses = () =>
    callWithMockFallback(
        () => client.get('/me/courses').then((r) => r.data),
        () => mockStore.getCourses(),
    );

export const fetchCourseTopics = (courseId) =>
    callWithMockFallback(
        () => client.get(`/me/courses/${courseId}/topics`).then((r) => r.data),
        () => mockStore.getCourseTopics(courseId),
    );

export const syncCourseNow = (courseId) =>
    callWithMockFallback(
        () => client.post(`/me/courses/${courseId}/sync`).then((r) => r.data),
        () => mockStore.syncCourse(courseId),
    );

export const fetchCoursePractice = (courseId) =>
    callWithMockFallback(
        () => client.get(`/me/courses/${courseId}/practice`).then((r) => r.data),
        () => mockStore.getCoursePractice(courseId),
    );

export const fetchDiagnosticPrompt = (courseId) =>
    callWithMockFallback(
        () => client.get(`/me/courses/${courseId}/diagnostic-prompt`).then((r) => r.data),
        () => mockStore.getDiagnosticPrompt(courseId),
    );

// ============================================================================
// §9 Practice quizzes
// ============================================================================

export const startAssessmentAttempt = (assessmentId) =>
    callWithMockFallback(
        () => client.post(`/me/assessments/${assessmentId}/attempts`).then((r) => r.data),
        () => mockStore.startAttempt(assessmentId),
    );

export const fetchAttempt = (attemptId) =>
    callWithMockFallback(
        () => client.get(`/me/attempts/${attemptId}`).then((r) => r.data),
        () => mockStore.getAttempt(attemptId),
    );

export const saveAttemptAnswer = (attemptId, questionId, optionIds) =>
    callWithMockFallback(
        () =>
            client
                .put(`/me/attempts/${attemptId}/answers/${questionId}`, {
                    option_ids: optionIds,
                })
                .then((r) => r.data),
        () => mockStore.saveAnswer(attemptId, questionId, { option_ids: optionIds }),
    );

export const submitAttempt = (attemptId, confirmUnanswered = false) =>
    callWithMockFallback(
        () =>
            client
                .post(`/me/attempts/${attemptId}/submit`, {
                    confirm_unanswered: confirmUnanswered,
                })
                .then((r) => r.data),
        () => mockStore.submitAttempt(attemptId, confirmUnanswered),
    );

// ============================================================================
// §11 Recommendations, goals and revision quizzes
// ============================================================================

export const fetchRecommendations = (state = 'active') =>
    callWithMockFallback(
        () => client.get('/me/recommendations', { params: { state } }).then((r) => r.data),
        () => mockStore.getRecommendations(state),
    );

export const fetchRecommendationShortlist = () =>
    callWithMockFallback(
        () => client.get('/me/recommendations/shortlist').then((r) => r.data),
        () => mockStore.getShortlist(),
    );

export const acceptRecommendation = (id) =>
    callWithMockFallback(
        () => client.post(`/me/recommendations/${id}/accept`).then((r) => r.data),
        () => mockStore.acceptRecommendation(id),
    );

export const postponeRecommendation = (id, reminderAt) =>
    callWithMockFallback(
        () =>
            client
                .post(`/me/recommendations/${id}/postpone`, { reminder_at: reminderAt })
                .then((r) => r.data),
        () => mockStore.postponeRecommendation(id, reminderAt),
    );

export const dismissRecommendation = (id) =>
    callWithMockFallback(
        () => client.post(`/me/recommendations/${id}/dismiss`).then((r) => r.data),
        () => mockStore.dismissRecommendation(id),
    );

export const restoreRecommendation = (id) =>
    callWithMockFallback(
        () => client.post(`/me/recommendations/${id}/restore`).then((r) => r.data),
        () => mockStore.restoreRecommendation(id),
    );

export const fetchMyGoals = () =>
    callWithMockFallback(
        () => client.get('/me/goals').then((r) => r.data),
        () => mockStore.getGoals(),
    );

export const addMasteryGoal = (topicId) =>
    callWithMockFallback(
        () => client.post(`/me/topics/${topicId}/goals`).then((r) => r.data),
        () => mockStore.addGoal(topicId, 'mastery'),
    );

export const removeMasteryGoal = (topicId) =>
    callWithMockFallback(
        () => client.delete(`/me/topics/${topicId}/goals`).then((r) => r.data),
        () => mockStore.removeGoal(topicId, 'mastery'),
    );

export const addHelpRequest = (topicId) =>
    callWithMockFallback(
        () => client.post(`/me/topics/${topicId}/help-requests`).then((r) => r.data),
        () => mockStore.addGoal(topicId, 'help'),
    );

export const removeHelpRequest = (topicId) =>
    callWithMockFallback(
        () => client.delete(`/me/topics/${topicId}/help-requests`).then((r) => r.data),
        () => mockStore.removeGoal(topicId, 'help'),
    );

export const startRevisionQuiz = (topicId) =>
    callWithMockFallback(
        () => client.post(`/me/topics/${topicId}/revision-quiz`).then((r) => r.data),
        () => mockStore.startRevisionQuiz(topicId),
    );

// ============================================================================
// §12 Lecturer tools
// ============================================================================

export const fetchLecturerCourseTopics = (courseId) =>
    callWithMockFallback(
        () => client.get(`/courses/${courseId}/topics`).then((r) => r.data),
        () => mockStore.getLecturerTopics(courseId),
    );

export const fetchTopicQuestions = (topicId) =>
    callWithMockFallback(
        () => client.get(`/topics/${topicId}/questions`).then((r) => r.data),
        () => mockStore.getTopicQuestions(topicId),
    );

export const createTopicQuestion = (topicId, body) =>
    callWithMockFallback(
        () => client.post(`/topics/${topicId}/questions`, body).then((r) => r.data),
        () => mockStore.createQuestion(topicId, body),
    );

export const updateQuestion = (questionId, body) =>
    callWithMockFallback(
        () => client.patch(`/questions/${questionId}`, body).then((r) => r.data),
        () => mockStore.updateQuestion(questionId, body),
    );

export const archiveQuestion = (questionId) =>
    callWithMockFallback(
        () => client.post(`/questions/${questionId}/archive`).then((r) => r.data),
        () => mockStore.archiveQuestion(questionId),
    );

export const regradeQuestion = (questionId, answerData) =>
    callWithMockFallback(
        () =>
            client
                .post(`/questions/${questionId}/regrade`, { answer_data: answerData })
                .then((r) => r.data),
        () => mockStore.regradeQuestion(questionId, answerData),
    );

export const importKlenamQuestions = (topicId, file, format) => {
    const formData = new FormData();
    formData.append('file', file);
    if (format) formData.append('format', format);

    return callWithMockFallback(
        () =>
            client
                .post(`/topics/${topicId}/questions/import`, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                })
                .then((r) => r.data),
        () => mockStore.importKlenamQuestions(topicId, file, format),
    );
};

export const fetchCanvasItems = (courseId) =>
    callWithMockFallback(
        () => client.get(`/courses/${courseId}/canvas-items`).then((r) => r.data),
        () => mockStore.getCanvasItems(courseId),
    );

export const saveCanvasMappings = (courseId, mappings) =>
    callWithMockFallback(
        () =>
            client
                .put(`/courses/${courseId}/canvas-mappings`, { mappings })
                .then((r) => r.data),
        () => mockStore.saveCanvasMappings(courseId, mappings),
    );

export const fetchClassOverview = (courseId) =>
    callWithMockFallback(
        () => client.get(`/courses/${courseId}/overview`).then((r) => r.data),
        () => mockStore.getClassOverview(courseId),
    );

export const fetchLearnerDetail = (courseId, learnerId) =>
    callWithMockFallback(
        () =>
            client
                .get(`/courses/${courseId}/learners/${learnerId}`)
                .then((r) => r.data),
        () => mockStore.getLearnerDetail(courseId, learnerId),
    );
