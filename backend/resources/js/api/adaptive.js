import client from './client';

// ============================================================================
// §13–§14 Canvas connection, OAuth connect, adding Canvas courses, and topics
// ============================================================================

export const fetchCanvasStatus = () => client.get('/me/canvas').then((r) => r.data);

export const connectCanvasToken = (accessToken) =>
    client.post('/me/canvas', { access_token: accessToken }).then((r) => r.data);

export const startCanvasOAuthConnect = () =>
    client.post('/me/canvas/oauth').then((r) => r.data);

export const disconnectCanvasToken = () =>
    client.delete('/me/canvas').then((r) => r.data);

export const fetchTeachingCanvasCourses = () =>
    client.get('/canvas/courses').then((r) => r.data);

export const addCanvasCourse = (body) =>
    client.post('/canvas/courses', body).then((r) => r.data);

export const createCourseTopic = (courseId, body) =>
    client.post(`/courses/${courseId}/topics`, body).then((r) => r.data);

export const updateCourseTopic = (topicId, body) =>
    client.patch(`/topics/${topicId}`, body).then((r) => r.data);

export const deleteCourseTopic = (topicId) =>
    client.delete(`/topics/${topicId}`).then((r) => r.data);

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

// ============================================================================
// §15 Study resources, outside sources, help requests, progress, privacy
// ============================================================================

export const fetchTopicResources = (topicId) =>
    client.get(`/topics/${topicId}/resources`).then((r) => r.data);

export const createTopicResource = (topicId, body) =>
    client.post(`/topics/${topicId}/resources`, body).then((r) => r.data);

export const updateTopicResource = (resourceId, body) =>
    client.patch(`/resources/${resourceId}`, body).then((r) => r.data);

export const deleteTopicResource = (resourceId) =>
    client.delete(`/resources/${resourceId}`).then((r) => r.data);

export const fetchLearningSourcesStatus = () =>
    client.get('/learning-sources').then((r) => r.data);

export const suggestTopicVideos = (topicId) =>
    client.post(`/topics/${topicId}/resources/suggest-videos`).then((r) => r.data);

export const searchOnlineQuestions = (topicId, params) =>
    client.get(`/topics/${topicId}/question-search`, { params }).then((r) => r.data);

export const importOnlineQuestions = (topicId, questions) =>
    client
        .post(`/topics/${topicId}/question-search/import`, { questions })
        .then((r) => r.data);

export const fetchCourseHelpRequests = (courseId) =>
    client.get(`/courses/${courseId}/help-requests`).then((r) => r.data);

export const acknowledgeHelpRequest = (helpRequestId) =>
    client.post(`/help-requests/${helpRequestId}/acknowledge`).then((r) => r.data);

export const fetchTopicHistory = (topicId) =>
    client.get(`/me/topics/${topicId}/history`).then((r) => r.data);

export const exportMyData = () => client.get('/me/data-export').then((r) => r.data);

export const deleteMyAccount = (body) =>
    client.delete('/me/account', { data: body }).then((r) => r.data);
