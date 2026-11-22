import client from './client';

// ============================================================================
// §10 Courses, topic performance and Canvas sync
// ============================================================================

export const fetchMyCourses = () => client.get('/me/courses').then((r) => r.data);

export const fetchCourseTopics = (courseId) =>
    client.get(`/me/courses/${courseId}/topics`).then((r) => r.data);

export const syncCourseNow = (courseId) =>
    client.post(`/me/courses/${courseId}/sync`).then((r) => r.data);

export const fetchCoursePractice = (courseId) =>
    client.get(`/me/courses/${courseId}/practice`).then((r) => r.data);

export const fetchDiagnosticPrompt = (courseId) =>
    client.get(`/me/courses/${courseId}/diagnostic-prompt`).then((r) => r.data);

// ============================================================================
// §9 Practice quizzes
// ============================================================================

export const startAssessmentAttempt = (assessmentId) =>
    client.post(`/me/assessments/${assessmentId}/attempts`).then((r) => r.data);

export const fetchAttempt = (attemptId) =>
    client.get(`/me/attempts/${attemptId}`).then((r) => r.data);

export const saveAttemptAnswer = (attemptId, questionId, optionIds) =>
    client
        .put(`/me/attempts/${attemptId}/answers/${questionId}`, {
            option_ids: optionIds,
        })
        .then((r) => r.data);

export const submitAttempt = (attemptId, confirmUnanswered = false) =>
    client
        .post(`/me/attempts/${attemptId}/submit`, {
            confirm_unanswered: confirmUnanswered,
        })
        .then((r) => r.data);

// ============================================================================
// §11 Recommendations, goals and revision quizzes
// ============================================================================

export const fetchRecommendations = (state = 'active') =>
    client.get('/me/recommendations', { params: { state } }).then((r) => r.data);

export const fetchRecommendationShortlist = () =>
    client.get('/me/recommendations/shortlist').then((r) => r.data);

export const acceptRecommendation = (id) =>
    client.post(`/me/recommendations/${id}/accept`).then((r) => r.data);

export const postponeRecommendation = (id, reminderAt) =>
    client
        .post(`/me/recommendations/${id}/postpone`, { reminder_at: reminderAt })
        .then((r) => r.data);

export const dismissRecommendation = (id) =>
    client.post(`/me/recommendations/${id}/dismiss`).then((r) => r.data);

export const restoreRecommendation = (id) =>
    client.post(`/me/recommendations/${id}/restore`).then((r) => r.data);

export const fetchMyGoals = () => client.get('/me/goals').then((r) => r.data);

export const addMasteryGoal = (topicId) =>
    client.post(`/me/topics/${topicId}/goals`).then((r) => r.data);

export const removeMasteryGoal = (topicId) =>
    client.delete(`/me/topics/${topicId}/goals`).then((r) => r.data);

export const addHelpRequest = (topicId) =>
    client.post(`/me/topics/${topicId}/help-requests`).then((r) => r.data);

export const removeHelpRequest = (topicId) =>
    client.delete(`/me/topics/${topicId}/help-requests`).then((r) => r.data);

export const startRevisionQuiz = (topicId) =>
    client.post(`/me/topics/${topicId}/revision-quiz`).then((r) => r.data);

// ============================================================================
// §12 Lecturer tools
// ============================================================================

export const fetchLecturerCourseTopics = (courseId) =>
    client.get(`/courses/${courseId}/topics`).then((r) => r.data);

export const fetchTopicQuestions = (topicId) =>
    client.get(`/topics/${topicId}/questions`).then((r) => r.data);

export const createTopicQuestion = (topicId, body) =>
    client.post(`/topics/${topicId}/questions`, body).then((r) => r.data);

export const updateQuestion = (questionId, body) =>
    client.patch(`/questions/${questionId}`, body).then((r) => r.data);

export const archiveQuestion = (questionId) =>
    client.post(`/questions/${questionId}/archive`).then((r) => r.data);

export const regradeQuestion = (questionId, answerData) =>
    client
        .post(`/questions/${questionId}/regrade`, { answer_data: answerData })
        .then((r) => r.data);

export const importKlenamQuestions = (topicId, file, format) => {
    const formData = new FormData();
    formData.append('file', file);
    if (format) formData.append('format', format);

    return client
        .post(`/topics/${topicId}/questions/import`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then((r) => r.data);
};

export const fetchCanvasItems = (courseId) =>
    client.get(`/courses/${courseId}/canvas-items`).then((r) => r.data);

export const saveCanvasMappings = (courseId, mappings) =>
    client
        .put(`/courses/${courseId}/canvas-mappings`, { mappings })
        .then((r) => r.data);

export const fetchClassOverview = (courseId) =>
    client.get(`/courses/${courseId}/overview`).then((r) => r.data);

export const fetchLearnerDetail = (courseId, learnerId) =>
    client.get(`/courses/${courseId}/learners/${learnerId}`).then((r) => r.data);
