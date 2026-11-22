/**
 * In-memory mock fallback matching the exact response shapes in AGENTS.md §9–§12
 * and the Canvas fixture demo data. Used only when a backend controller route has
 * not been merged yet (HTTP 404 "The route api/... could not be found.").
 */

const state = {
    usingMock: false,
    courses: [
        { id: 1, name: 'Web Technologies', code: 'CS341' },
        { id: 2, name: 'Programming in Python', code: 'CS112' },
    ],
    topicsByCourse: {
        1: [
            {
                topic_id: 1,
                topic_name: 'HTML Basics & Semantic Structure',
                status: 'strong',
                label: 'Strong performance',
                earned_marks: 18,
                max_marks: 20,
                performance_display: 90,
                coverage_band: 'broad',
                difficulty_class: 'challenging',
                confidence: 'high',
                confidence_reasons: [],
                evidence_age: 'recent',
                last_assessed_at: '2026-11-18T14:30:00+00:00',
                pending_count: 0,
                conflict: null,
                explanation: 'You have scored 18/20 (90%) on HTML Basics & Semantic Structure.',
            },
            {
                topic_id: 2,
                topic_name: 'CSS Layout, Flexbox & Grid',
                status: 'strong_unconfirmed',
                label: 'Strong so far — more evidence needed',
                earned_marks: 8,
                max_marks: 9,
                performance_display: 88.8,
                coverage_band: 'low',
                difficulty_class: 'mixed',
                confidence: 'limited',
                confidence_reasons: [
                    { code: 'low_coverage', text: 'Fewer than 10 marks of evidence so far.' },
                ],
                evidence_age: 'recent',
                last_assessed_at: '2026-11-19T10:15:00+00:00',
                pending_count: 0,
                conflict: null,
                explanation:
                    'You have scored 8/9 (88.8%) on CSS Layout, Flexbox & Grid so far. More assessment evidence is needed before mastery can be confirmed.',
            },
            {
                topic_id: 3,
                topic_name: 'JavaScript DOM & Async Logic',
                status: 'needs_help',
                label: 'Needs help',
                earned_marks: 11,
                max_marks: 25,
                performance_display: 44,
                coverage_band: 'broad',
                difficulty_class: 'challenging',
                confidence: 'moderate',
                confidence_reasons: [
                    { code: 'conflict_reduced', text: 'Your results from different sources disagree.' },
                ],
                evidence_age: 'recent',
                last_assessed_at: '2026-11-20T16:00:00+00:00',
                pending_count: 1,
                conflict: {
                    conflict_detected: true,
                    confidence_reduced: true,
                    pairs: [
                        {
                            groups: ['learnloop', 'canvas'],
                            a: {
                                group: 'learnloop',
                                label: 'LearnLoop practice',
                                earned_marks: 8,
                                max_marks: 10,
                                performance_display: 80,
                            },
                            b: {
                                group: 'canvas',
                                label: 'Canvas',
                                earned_marks: 3,
                                max_marks: 15,
                                performance_display: 20,
                            },
                            difference_display: 60,
                            both_meet_minimum: true,
                            qualifies_for_reduction: true,
                        },
                    ],
                },
                explanation: 'You have scored 11/25 (44%) on JavaScript DOM & Async Logic.',
            },
            {
                topic_id: 4,
                topic_name: 'REST APIs & Authentication',
                status: 'not_enough_evidence',
                label: 'Awaiting marking',
                earned_marks: null,
                max_marks: null,
                performance_display: null,
                coverage_band: null,
                difficulty_class: null,
                confidence: null,
                confidence_reasons: [],
                evidence_age: null,
                last_assessed_at: null,
                pending_count: 1,
                conflict: null,
                explanation: 'Your work on REST APIs & Authentication is awaiting marking.',
            },
        ],
        2: [
            {
                topic_id: 5,
                topic_name: 'Python Basics & Control Flow',
                status: 'getting_there',
                label: 'Getting there',
                earned_marks: 19.99,
                max_marks: 25,
                performance_display: 79.9,
                coverage_band: 'broad',
                difficulty_class: 'basic',
                confidence: 'moderate',
                confidence_reasons: [
                    { code: 'mostly_basic', text: 'Based mostly on easier questions.' },
                ],
                evidence_age: 'older',
                last_assessed_at: '2026-10-05T09:00:00+00:00',
                pending_count: 0,
                conflict: null,
                explanation: 'You have scored 19.99/25 (79.9%) on Python Basics & Control Flow.',
            },
            {
                topic_id: 6,
                topic_name: 'Functions & Scope',
                status: 'not_enough_evidence',
                label: 'Not assessed',
                earned_marks: null,
                max_marks: null,
                performance_display: null,
                coverage_band: null,
                difficulty_class: null,
                confidence: null,
                confidence_reasons: [],
                evidence_age: null,
                last_assessed_at: null,
                pending_count: 0,
                conflict: null,
                explanation: 'You have not been assessed on Functions & Scope yet.',
            },
        ],
    },
    practiceByCourse: {
        1: [
            {
                id: 101,
                title: 'HTML & CSS Foundations Practice',
                topic: { id: 2, name: 'CSS Layout, Flexbox & Grid' },
            },
            {
                id: 102,
                title: 'JavaScript Async & Event Loop Practice',
                topic: { id: 3, name: 'JavaScript DOM & Async Logic' },
            },
        ],
        2: [
            {
                id: 201,
                title: 'Python Control Flow Warmup',
                topic: { id: 5, name: 'Python Basics & Control Flow' },
            },
        ],
    },
    goals: [
        { id: 1, topic: { id: 2, name: 'CSS Layout, Flexbox & Grid' }, goal_type: 'mastery' },
        { id: 2, topic: { id: 3, name: 'JavaScript DOM & Async Logic' }, goal_type: 'help' },
    ],
    recommendations: [
        {
            id: 1,
            topic: { id: 3, name: 'JavaScript DOM & Async Logic' },
            state: 'active',
            priority: 'high',
            primary_reason: 'needs_help',
            reason_codes: ['needs_help', 'help_requested', 'conflict_sufficient'],
            reasons: [
                'You have scored 11/25 (44%) on JavaScript DOM & Async Logic.',
                'You asked for help with this topic.',
                'Your LearnLoop practice results (80%) and Canvas results (20%) disagree by 60 points.',
            ],
            suggested_action: {
                key: 'revise_topic',
                text: 'Revise the topic, take a revision quiz, or ask for help.',
            },
            performance: {
                status: 'needs_help',
                label: 'Needs help',
                performance_display: 44,
                earned_marks: 11,
                max_marks: 25,
            },
            reminder_at: null,
            created_at: '2026-11-20T16:05:00+00:00',
        },
        {
            id: 2,
            topic: { id: 5, name: 'Python Basics & Control Flow' },
            state: 'active',
            priority: 'medium',
            primary_reason: 'getting_there',
            reason_codes: ['getting_there'],
            reasons: ['You have scored 19.99/25 (79.9%) on Python Basics & Control Flow.'],
            suggested_action: {
                key: 'revision_quiz',
                text: 'Take a revision quiz to keep improving.',
            },
            performance: {
                status: 'getting_there',
                label: 'Getting there',
                performance_display: 79.9,
                earned_marks: 19.99,
                max_marks: 25,
            },
            reminder_at: null,
            created_at: '2026-11-18T11:00:00+00:00',
        },
        {
            id: 3,
            topic: { id: 2, name: 'CSS Layout, Flexbox & Grid' },
            state: 'active',
            priority: 'low',
            primary_reason: 'strong_unconfirmed',
            reason_codes: ['strong_unconfirmed', 'mastery_goal'],
            reasons: [
                'You have scored 8/9 (88.8%) on CSS Layout, Flexbox & Grid so far. More assessment evidence is needed before mastery can be confirmed.',
                'You set a goal to master this topic.',
            ],
            suggested_action: {
                key: 'revision_quiz',
                text: 'Complete another assessment to confirm your mastery.',
            },
            performance: {
                status: 'strong_unconfirmed',
                label: 'Strong so far — more evidence needed',
                performance_display: 88.8,
                earned_marks: 8,
                max_marks: 9,
            },
            reminder_at: null,
            created_at: '2026-11-19T10:20:00+00:00',
        },
        {
            id: 4,
            topic: { id: 4, name: 'REST APIs & Authentication' },
            state: 'dismissed',
            priority: 'low',
            primary_reason: 'mastery_goal',
            reason_codes: ['mastery_goal'],
            reasons: ['You set a goal to master this topic.'],
            suggested_action: {
                key: 'diagnostic',
                text: 'Begin with a practice quiz on this topic.',
            },
            performance: {
                status: 'not_enough_evidence',
                label: 'Not assessed',
                performance_display: null,
                earned_marks: null,
                max_marks: null,
            },
            reminder_at: null,
            created_at: '2026-11-10T09:00:00+00:00',
        },
        {
            id: 5,
            topic: { id: 1, name: 'HTML Basics & Semantic Structure' },
            state: 'completed',
            priority: 'medium',
            primary_reason: 'getting_there',
            reason_codes: ['getting_there'],
            reasons: ['You have scored 12/20 (60%) on HTML Basics & Semantic Structure.'],
            suggested_action: {
                key: 'revision_quiz',
                text: 'Take a revision quiz to keep improving.',
            },
            performance: {
                status: 'getting_there',
                label: 'Getting there',
                performance_display: 60,
                earned_marks: 12,
                max_marks: 20,
            },
            reminder_at: null,
            created_at: '2026-11-01T09:00:00+00:00',
        },
    ],
    attempts: {},
    nextAttemptId: 500,
    lecturerTopicsByCourse: {
        1: [
            { id: 1, course_id: 1, name: 'HTML Basics & Semantic Structure', status: 'active', topic_size: 15, active_questions: 5, warnings: [] },
            {
                id: 2,
                course_id: 1,
                name: 'CSS Layout, Flexbox & Grid',
                status: 'active',
                topic_size: 9,
                active_questions: 3,
                warnings: [
                    'This topic has fewer than 10 available question-bank marks. Canvas evidence may be needed before mastery can be confirmed.',
                    'This topic has no Hard questions. Learners may have limited opportunities to demonstrate performance at the highest difficulty level.',
                ],
            },
            { id: 3, course_id: 1, name: 'JavaScript DOM & Async Logic', status: 'active', topic_size: 16, active_questions: 4, warnings: [] },
            {
                id: 4,
                course_id: 1,
                name: 'REST APIs & Authentication',
                status: 'active',
                topic_size: 4,
                active_questions: 2,
                warnings: [
                    'This topic has fewer than 10 available question-bank marks. Canvas evidence may be needed before mastery can be confirmed.',
                    'This topic has fewer than 3 active objective questions. Revision quizzes cannot be generated.',
                ],
            },
        ],
        2: [
            { id: 5, course_id: 2, name: 'Python Basics & Control Flow', status: 'active', topic_size: 12, active_questions: 4, warnings: [] },
            { id: 6, course_id: 2, name: 'Functions & Scope', status: 'active', topic_size: 10, active_questions: 3, warnings: [] },
        ],
    },
    questionsByTopic: {
        1: [
            {
                id: 10,
                topic_id: 1,
                question_type: 'mcq',
                question_text: 'Which HTML element represents the main dominant content of the <body> of a document?',
                difficulty: 'easy',
                max_marks: '3.00',
                status: 'active',
                source: 'learnloop',
                used_in_attempts: true,
                answer_data: {
                    options: [
                        { id: 'a', text: '<main>' },
                        { id: 'b', text: '<section>' },
                        { id: 'c', text: '<article>' },
                        { id: 'd', text: '<content>' },
                    ],
                    correct_option_ids: ['a'],
                },
                explanation: 'The <main> element represents the dominant content of the <body> of a document.',
            },
            {
                id: 11,
                topic_id: 1,
                question_type: 'true_false',
                question_text: 'An <img> element should always include an alt attribute for accessibility.',
                difficulty: 'easy',
                max_marks: '2.00',
                status: 'active',
                source: 'klenam',
                used_in_attempts: false,
                answer_data: {
                    options: [
                        { id: 'true', text: 'True' },
                        { id: 'false', text: 'False' },
                    ],
                    correct_option_ids: ['true'],
                },
                explanation: 'Screen readers rely on the alt attribute (or empty alt="" for decorative images).',
            },
        ],
        2: [
            {
                id: 20,
                topic_id: 2,
                question_type: 'mcq',
                question_text: 'In CSS Flexbox, which property aligns items along the main axis?',
                difficulty: 'medium',
                max_marks: '3.00',
                status: 'active',
                source: 'learnloop',
                used_in_attempts: true,
                answer_data: {
                    options: [
                        { id: 'a', text: 'justify-content' },
                        { id: 'b', text: 'align-items' },
                        { id: 'c', text: 'align-content' },
                        { id: 'd', text: 'flex-direction' },
                    ],
                    correct_option_ids: ['a'],
                },
                explanation: 'justify-content distributes space between and around items along the main axis.',
            },
        ],
        3: [
            {
                id: 30,
                topic_id: 3,
                question_type: 'mcq',
                question_text: 'What does Promise.all([p1, p2]) do if p2 rejects immediately?',
                difficulty: 'hard',
                max_marks: '4.00',
                status: 'active',
                source: 'learnloop',
                used_in_attempts: true,
                answer_data: {
                    options: [
                        { id: 'a', text: 'It rejects immediately with p2’s rejection reason.' },
                        { id: 'b', text: 'It waits for p1 to settle before rejecting.' },
                        { id: 'c', text: 'It resolves with an array containing undefined for p2.' },
                        { id: 'd', text: 'It retries p2 once automatically.' },
                    ],
                    correct_option_ids: ['a'],
                },
                explanation: 'Promise.all short-circuits and rejects as soon as any input promise rejects.',
            },
        ],
    },
    canvasItemsByCourse: {
        1: [
            {
                canvas_assignment_id: '2001',
                name: 'Lab 1: Semantic HTML Portfolio',
                points_possible: 20,
                submission_types: ['online_upload'],
                mapping: { topic_id: 1, difficulty: 'medium' },
                criteria: [],
            },
            {
                canvas_assignment_id: '2002',
                name: 'Midterm Project: Interactive Web App',
                points_possible: 25,
                submission_types: ['online_url'],
                mapping: null,
                criteria: [
                    {
                        canvas_criterion_id: 'crit_css',
                        description: 'Responsive CSS Grid & Flexbox layout',
                        points: 10,
                        mapping: { topic_id: 2, difficulty: 'medium' },
                    },
                    {
                        canvas_criterion_id: 'crit_js',
                        description: 'JavaScript DOM state & event handling',
                        points: 15,
                        mapping: { topic_id: 3, difficulty: 'hard' },
                    },
                ],
            },
            {
                canvas_assignment_id: '2003',
                name: 'Quiz 3: REST APIs & Sanctum Tokens',
                points_possible: 15,
                submission_types: ['online_quiz'],
                mapping: null,
                criteria: [],
            },
        ],
        2: [
            {
                canvas_assignment_id: '3001',
                name: 'Homework 1: Loops & Conditionals',
                points_possible: 25,
                submission_types: ['online_upload'],
                mapping: { topic_id: 5, difficulty: 'easy' },
                criteria: [],
            },
        ],
    },
    overviewByCourse: {
        1: {
            topics: [
                {
                    topic_id: 1,
                    topic_name: 'HTML Basics & Semantic Structure',
                    counts: { needs_help: 0, getting_there: 1, strong_unconfirmed: 0, strong: 2, not_enough_evidence: 0 },
                },
                {
                    topic_id: 2,
                    topic_name: 'CSS Layout, Flexbox & Grid',
                    counts: { needs_help: 0, getting_there: 1, strong_unconfirmed: 1, strong: 1, not_enough_evidence: 0 },
                },
                {
                    topic_id: 3,
                    topic_name: 'JavaScript DOM & Async Logic',
                    counts: { needs_help: 1, getting_there: 1, strong_unconfirmed: 0, strong: 1, not_enough_evidence: 0 },
                },
                {
                    topic_id: 4,
                    topic_name: 'REST APIs & Authentication',
                    counts: { needs_help: 0, getting_there: 0, strong_unconfirmed: 0, strong: 1, not_enough_evidence: 2 },
                },
            ],
            learners: [
                {
                    learner: { id: 4, name: 'Alex Morgan' },
                    topics: [
                        { topic_id: 1, status: 'strong', performance_display: 95, confidence: 'high', conflict: false, pending_count: 0 },
                        { topic_id: 2, status: 'strong', performance_display: 88, confidence: 'high', conflict: false, pending_count: 0 },
                        { topic_id: 3, status: 'strong', performance_display: 84, confidence: 'moderate', conflict: false, pending_count: 0 },
                        { topic_id: 4, status: 'strong', performance_display: 90, confidence: 'moderate', conflict: false, pending_count: 0 },
                    ],
                },
                {
                    learner: { id: 5, name: 'Jamie Lee' },
                    topics: [
                        { topic_id: 1, status: 'strong', performance_display: 90, confidence: 'high', conflict: false, pending_count: 0 },
                        { topic_id: 2, status: 'strong_unconfirmed', performance_display: 88.8, confidence: 'limited', conflict: false, pending_count: 0 },
                        { topic_id: 3, status: 'needs_help', performance_display: 44, confidence: 'moderate', conflict: true, pending_count: 1 },
                        { topic_id: 4, status: 'not_enough_evidence', performance_display: null, confidence: null, conflict: false, pending_count: 1 },
                    ],
                },
                {
                    learner: { id: 6, name: 'Sam Patel' },
                    topics: [
                        { topic_id: 1, status: 'getting_there', performance_display: 65, confidence: 'limited', conflict: false, pending_count: 0 },
                        { topic_id: 2, status: 'getting_there', performance_display: 70, confidence: 'limited', conflict: false, pending_count: 0 },
                        { topic_id: 3, status: 'getting_there', performance_display: 60, confidence: 'limited', conflict: false, pending_count: 0 },
                        { topic_id: 4, status: 'not_enough_evidence', performance_display: null, confidence: null, conflict: false, pending_count: 0 },
                    ],
                },
            ],
        },
    },
};

function makeAttemptPayload(attemptId, assessmentId) {
    return {
        attempt_id: attemptId,
        assessment_id: assessmentId,
        status: 'in_progress',
        started_at: new Date().toISOString(),
        questions: [
            {
                question_id: 10,
                position: 1,
                type: 'mcq',
                text: 'Which HTML element represents the main dominant content of the <body> of a document?',
                max_marks: 3,
                options: [
                    { id: 'a', text: '<main>' },
                    { id: 'b', text: '<section>' },
                    { id: 'c', text: '<article>' },
                    { id: 'd', text: '<content>' },
                ],
                response: null,
                _correct: ['a'],
                _explanation: 'The <main> element represents the dominant content of the <body> of a document.',
            },
            {
                question_id: 20,
                position: 2,
                type: 'mcq',
                text: 'In CSS Flexbox, which property aligns items along the main axis?',
                max_marks: 3,
                options: [
                    { id: 'a', text: 'justify-content' },
                    { id: 'b', text: 'align-items' },
                    { id: 'c', text: 'align-content' },
                    { id: 'd', text: 'flex-direction' },
                ],
                response: null,
                _correct: ['a'],
                _explanation: 'justify-content distributes space between and around items along the main axis.',
            },
            {
                question_id: 11,
                position: 3,
                type: 'true_false',
                text: 'An <img> element should always include an alt attribute for accessibility.',
                max_marks: 2,
                options: [
                    { id: 'true', text: 'True' },
                    { id: 'false', text: 'False' },
                ],
                response: null,
                _correct: ['true'],
                _explanation: 'Screen readers rely on the alt attribute (or empty alt="" for decorative images).',
            },
        ],
    };
}

function stripInternalAttemptFields(attempt) {
    return {
        attempt_id: attempt.attempt_id,
        assessment_id: attempt.assessment_id,
        status: attempt.status,
        started_at: attempt.started_at,
        questions: attempt.questions.map(({ _correct, _explanation, ...q }) => q),
    };
}

export const mockStore = {
    isUsingMock: () => state.usingMock,
    markUsed: () => {
        state.usingMock = true;
    },
    getCourses: () => state.courses,
    getCourseTopics: (courseId) => ({
        data: state.topicsByCourse[Number(courseId)] ?? state.topicsByCourse[1],
    }),
    syncCourse: () => ({
        created: 1,
        unchanged: 3,
        skipped: 0,
        flagged: 0,
    }),
    getCoursePractice: (courseId) => state.practiceByCourse[Number(courseId)] ?? [],
    getDiagnosticPrompt: (courseId) => ({
        show: (state.topicsByCourse[Number(courseId)] ?? []).some(
            (t) => t.status === 'not_enough_evidence' && t.pending_count === 0,
        ),
    }),
    getGoals: () => state.goals,
    addGoal: (topicId, goalType) => {
        const numericId = Number(topicId);
        const allTopics = Object.values(state.topicsByCourse).flat();
        const found = allTopics.find((t) => t.topic_id === numericId);
        const existing = state.goals.find((g) => g.topic.id === numericId && g.goal_type === goalType);
        if (!existing) {
            state.goals = [
                ...state.goals,
                {
                    id: Date.now(),
                    topic: { id: numericId, name: found?.topic_name ?? `Topic ${numericId}` },
                    goal_type: goalType,
                },
            ];
        }
        return { saved: true };
    },
    removeGoal: (topicId, goalType) => {
        const numericId = Number(topicId);
        state.goals = state.goals.filter(
            (g) => !(g.topic.id === numericId && g.goal_type === goalType),
        );
    },
    getRecommendations: (sectionState = 'active') => {
        if (sectionState === 'history') {
            return {
                data: state.recommendations.filter((r) =>
                    ['completed', 'obsolete', 'superseded'].includes(r.state),
                ),
            };
        }
        return {
            data: state.recommendations.filter((r) => r.state === sectionState),
        };
    },
    getShortlist: () => ({
        data: state.recommendations.filter((r) => r.state === 'active').slice(0, 5),
    }),
    acceptRecommendation: (id) => {
        const rec = state.recommendations.find((r) => r.id === Number(id));
        if (rec) rec.state = 'accepted';
        return rec;
    },
    postponeRecommendation: (id, reminderAt) => {
        const rec = state.recommendations.find((r) => r.id === Number(id));
        if (rec) {
            rec.state = 'postponed';
            rec.reminder_at = reminderAt;
        }
        return rec;
    },
    dismissRecommendation: (id) => {
        const rec = state.recommendations.find((r) => r.id === Number(id));
        if (rec) rec.state = 'dismissed';
        return rec;
    },
    restoreRecommendation: (id) => {
        const rec = state.recommendations.find((r) => r.id === Number(id));
        if (!rec) return { data: null, restored: false, message: 'Recommendation not found.' };
        // Show both branches: topic 4 stays dismissed if no longer applicable, or restores if active
        rec.state = 'active';
        return {
            data: rec,
            restored: true,
            message: 'Recommendation restored to active.',
        };
    },
    startAttempt: (assessmentId) => {
        const id = state.nextAttemptId++;
        const attempt = makeAttemptPayload(id, Number(assessmentId));
        state.attempts[id] = attempt;
        return stripInternalAttemptFields(attempt);
    },
    startRevisionQuiz: (topicId) => {
        if (Number(topicId) === 4) {
            const err = new Error('Revision quiz requires at least 3 active objective questions.');
            err.response = {
                status: 422,
                data: {
                    message:
                        'This topic has fewer than 3 active objective questions. Revision quizzes cannot be generated.',
                },
            };
            throw err;
        }
        const id = state.nextAttemptId++;
        const attempt = makeAttemptPayload(id, 900 + Number(topicId));
        state.attempts[id] = attempt;
        return stripInternalAttemptFields(attempt);
    },
    getAttempt: (attemptId) => {
        const id = Number(attemptId);
        if (!state.attempts[id]) {
            state.attempts[id] = makeAttemptPayload(id, 101);
        }
        return stripInternalAttemptFields(state.attempts[id]);
    },
    saveAnswer: (attemptId, questionId, body) => {
        const id = Number(attemptId);
        if (!state.attempts[id]) {
            state.attempts[id] = makeAttemptPayload(id, 101);
        }
        const q = state.attempts[id].questions.find((item) => item.question_id === Number(questionId));
        if (q) {
            const selected = body?.option_ids ?? [];
            q.response = selected.length > 0 ? { option_ids: selected } : null;
        }
        return { saved: true };
    },
    submitAttempt: (attemptId, confirmUnanswered) => {
        const id = Number(attemptId);
        if (!state.attempts[id]) {
            state.attempts[id] = makeAttemptPayload(id, 101);
        }
        const attempt = state.attempts[id];
        const unanswered = attempt.questions.filter(
            (q) => !q.response || !q.response.option_ids || q.response.option_ids.length === 0,
        ).length;

        if (unanswered > 0 && !confirmUnanswered) {
            const err = new Error('Unanswered questions require confirmation.');
            err.response = {
                status: 422,
                data: {
                    message: `You have ${unanswered} unanswered question(s). Blank answers count as zero.`,
                    unanswered,
                    requires_confirmation: true,
                },
            };
            throw err;
        }

        attempt.status = 'submitted';
        let earned = 0;
        let max = 0;
        const questions = attempt.questions.map((q) => {
            const selected = q.response?.option_ids ?? [];
            const isCorrect =
                selected.length === q._correct.length &&
                selected.every((opt) => q._correct.includes(opt));
            const itemEarned = isCorrect ? q.max_marks : 0;
            earned += itemEarned;
            max += q.max_marks;
            return {
                question_id: q.question_id,
                position: q.position,
                correct: isCorrect,
                earned_marks: itemEarned,
                max_marks: q.max_marks,
                awaiting_marking: false,
                explanation: q._explanation,
            };
        });

        return {
            attempt_id: attempt.attempt_id,
            status: 'submitted',
            finalised_at: new Date().toISOString(),
            earned_marks: earned,
            max_marks: max,
            performance_display: max > 0 ? Math.floor((earned * 1000) / max) / 10 : null,
            questions,
        };
    },
    // Lecturer mock helpers
    getLecturerTopics: (courseId) =>
        state.lecturerTopicsByCourse[Number(courseId)] ?? state.lecturerTopicsByCourse[1],
    getTopicQuestions: (topicId) => state.questionsByTopic[Number(topicId)] ?? [],
    createQuestion: (topicId, body) => {
        const numericTopicId = Number(topicId);
        const created = {
            id: Date.now(),
            topic_id: numericTopicId,
            status: 'active',
            source: 'learnloop',
            used_in_attempts: false,
            ...body,
        };
        state.questionsByTopic[numericTopicId] = [
            ...(state.questionsByTopic[numericTopicId] ?? []),
            created,
        ];
        return created;
    },
    updateQuestion: (questionId, body) => {
        const numericId = Number(questionId);
        for (const list of Object.values(state.questionsByTopic)) {
            const idx = list.findIndex((q) => q.id === numericId);
            if (idx !== -1) {
                list[idx] = { ...list[idx], ...body };
                return list[idx];
            }
        }
        return body;
    },
    archiveQuestion: (questionId) => {
        const numericId = Number(questionId);
        for (const list of Object.values(state.questionsByTopic)) {
            const q = list.find((item) => item.id === numericId);
            if (q) {
                q.status = 'inactive';
                return q;
            }
        }
        return { id: numericId, status: 'inactive' };
    },
    regradeQuestion: (questionId, answerData) => {
        const numericId = Number(questionId);
        for (const list of Object.values(state.questionsByTopic)) {
            const q = list.find((item) => item.id === numericId);
            if (q) {
                q.answer_data = answerData;
            }
        }
        return { regraded: 2, unchanged: 1 };
    },
    importKlenamQuestions: (topicId) => {
        const numericTopicId = Number(topicId);
        const importedQ = {
            id: Date.now(),
            topic_id: numericTopicId,
            question_type: 'mcq',
            question_text: 'Imported Klenam question: Which HTTP status indicates an unauthenticated request?',
            difficulty: 'medium',
            max_marks: '2.00',
            status: 'active',
            source: 'klenam',
            used_in_attempts: false,
            answer_data: {
                options: [
                    { id: 'a', text: '401 Unauthorized' },
                    { id: 'b', text: '403 Forbidden' },
                    { id: 'c', text: '404 Not Found' },
                    { id: 'd', text: '422 Unprocessable Content' },
                ],
                correct_option_ids: ['a'],
            },
            explanation: '401 indicates missing or invalid authentication credentials.',
        };
        state.questionsByTopic[numericTopicId] = [
            ...(state.questionsByTopic[numericTopicId] ?? []),
            importedQ,
        ];
        return {
            imported: [importedQ.id],
            rejected: [{ row: 2, reason: 'Duplicate question text in this topic.' }],
        };
    },
    getCanvasItems: (courseId) =>
        state.canvasItemsByCourse[Number(courseId)] ?? state.canvasItemsByCourse[1],
    saveCanvasMappings: (courseId, mappings) => {
        const items = state.canvasItemsByCourse[Number(courseId)] ?? state.canvasItemsByCourse[1];
        for (const m of mappings) {
            const item = items.find((a) => a.canvas_assignment_id === String(m.canvas_assignment_id));
            if (!item) continue;
            if (!m.canvas_criterion_id) {
                item.mapping = m.topic_id
                    ? { topic_id: Number(m.topic_id), difficulty: m.difficulty || 'medium' }
                    : null;
            } else {
                const crit = item.criteria.find(
                    (c) => c.canvas_criterion_id === String(m.canvas_criterion_id),
                );
                if (crit) {
                    crit.mapping = m.topic_id
                        ? { topic_id: Number(m.topic_id), difficulty: m.difficulty || 'medium' }
                        : null;
                }
            }
        }
        return [...items];
    },
    getClassOverview: (courseId) =>
        state.overviewByCourse[Number(courseId)] ?? state.overviewByCourse[1],
    getLearnerDetail: (courseId, learnerId) => {
        const overview = state.overviewByCourse[Number(courseId)] ?? state.overviewByCourse[1];
        const found = overview.learners.find((l) => l.learner.id === Number(learnerId));
        return {
            learner: found?.learner ?? { id: Number(learnerId), name: 'Jamie Lee' },
            topics: state.topicsByCourse[Number(courseId)] ?? state.topicsByCourse[1],
        };
    },
};
