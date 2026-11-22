# AGENTS.md — Instructions for AI coding agents on LearnLoop

Read this file before every task. It applies to every agent working in this repository (Claude, Antigravity, or any other).

## 1. What LearnLoop is

An adaptive-learning extension for Ashesi's Canvas (plus Klenam AI). It reads learners' scores, works out per-topic performance and confidence, and recommends what to practise. It also runs its own practice quizzes.

**Source of truth:**
1. `docs/spec/LearnLoop_Adaptive_Engine_Spec_v0.7.md` — baseline specification
2. `docs/spec/LearnLoop_Adaptive_Engine_v0.8_Amendment.md` — Canvas/Klenam changes; overrides v0.7 where they conflict

If code and spec disagree, the spec wins. If the spec is unclear, stop and ask the team; do not invent a rule.

## 2. Decisions you must never change

- **T1** Conflicts: a 30+ point difference between two source groups is flagged even if a group has < 5 maximum marks; confidence drops one level only when both groups in that pair have ≥ 5 maximum marks; at most one level overall.
- **T2** Topic size (question bank) is never the coverage denominator.
- **T3** "Never again" recommendations are not resurrected by ordinary new evidence; learners can restore them.
- **T4** ≥ 80% with < 10 marks of evidence is `strong_unconfirmed`, never `strong`.
- **C13** Displayed percentages are always rounded **down** to one decimal place. Status uses exact values.
- **D10** Missing difficulty defaults to `medium`.

Pending decisions (D1-R, D2–D4, D9, D13–D17) use the defaults written in the spec. Do not change them without the team.

## 3. Who owns what

| Area | Owner |
|---|---|
| Calculation core (`app/Services/Adaptive/Calculation`), evidence assembly, recommendation engine and reconciliation | Claude |
| `CanvasGateway` / `KlenamImporter` interfaces, fixture adapter and sample data | Claude |
| API response shapes (spec §13.4) | Claude defines, Antigravity implements |
| Project setup: routes, Sanctum, users/roles, migrations, database | Antigravity |
| React app and all screens | Antigravity |
| Real Canvas adapters (token / OAuth) | Antigravity |
| Running the app, migrations and tests on the team's machine | Antigravity |
| Reviews, spec and decision register | Claude |

Do not rewrite code in an area you do not own. If it needs to change, describe the change and let the owner make it.

## 4. Git workflow

- `main` is only updated by the team, by merging reviewed branches.
- Each agent works on its own branch: `setup`, `engine-core`, `frontend`, `canvas`, etc.
- Shared files change only on the `setup` branch: `routes/api.php`, `bootstrap/app.php`, `composer.json`, `package.json`, `.env.example`, `phpunit.xml`.
- Never commit `.env`, tokens, passwords or `vendor/`.
- Small commits with clear messages.

## 5. Code conventions

- Laravel 12, PHP ^8.2. Backend lives in `backend/`.
- **No business logic in controllers or React components.** Controllers call services; React displays API data.
- Marks are stored as `DECIMAL(6,2)` and calculated as **integer hundredths** (`App\Services\Adaptive\Calculation\Marks`). Never compare floats against thresholds.
- States and types: `varchar` columns cast to PHP backed enums (`app/Enums/Adaptive`). No MySQL `ENUM`.
- Provisional numbers (thresholds, limits) live in `config/adaptive.php`, not hard-coded in services.
- The calculation core is framework-free (no facades, no Eloquent) so it can be unit-tested without a database.
- Learner endpoints never return answer keys or other learners' data.

## 6. Tests

- Every business rule gets a test. Spec §16 lists the expected cases.
- Unit tests for the calculation core: `tests/Unit/Adaptive`. Feature tests: `tests/Feature/Adaptive`.
- Run: `php artisan test` (from `backend/`).
- Never report a test as passing unless you ran it and saw the output.

## 7. Reporting back

At the end of each task, report:
1. Files created or changed, and why
2. Commands run, with their real output
3. Tests run and results
4. Anything blocked, and any decision the team needs to make

## 8. Current tasks

| Task | Owner | Branch | Status |
|---|---|---|---|
| Project setup: Sanctum, API routes, roles, MariaDB | Antigravity | `setup` | Merged |
| Calculation core: performance, status, display, coverage, difficulty, confidence, conflicts, recency | Claude | `engine-core` | Merged |
| Adaptive schema (courses, topics, questions, assessments, attempts, answers, mark versions, Canvas mappings, external results) + evidence assembly | Claude | `adaptive-schema` | Ready for review |
| React app scaffold | Antigravity | `frontend` | In progress |
| Practice quiz service: start attempt (freeze items), autosave, submit, auto-mark | Claude | `practice-quiz` | Ready for review |
| Practice quiz API controllers + routes (contract below) | Antigravity | after `practice-quiz` merges | Not started |
| Canvas gateway, fixture adapter, sync service, course access, topic performance response, demo seeder | Claude | `canvas-fixture` | Ready for review |
| Dashboard / topic / Canvas sync API controllers + routes (contract §10) | Antigravity | after `canvas-fixture` merges | Not started |
| Canvas token adapter (`CanvasGateway` implementation using a personal access token) | Antigravity | later | Not started |
| Recommendation engine, goals/help requests, revision quiz generator | Claude | `recommendations` | Ready for review |
| Recommendation, goal and revision API controllers + routes (contract §11) | Antigravity | after `recommendations` merges | Not started |
| Lecturer tools: question bank rules, regrade, Klenam import, Canvas mapping, class overview | Claude | `lecturer-tools` | Ready for review |
| Lecturer API controllers + screens (contract §12) | Antigravity | after `lecturer-tools` merges | Not started |

**Environment facts:** MariaDB 12.2.2 (database `learnloop`, user `learnloop`). PHP 8.2. Laravel 12.72. Tests run on in-memory SQLite (`phpunit.xml`); never point tests at the real database.

**Schema notes for the API/UI:**
- `questions.answer_data` and `attempt_items.marking_snapshot` are hidden on serialisation; learner endpoints must never expose them.
- One in-progress attempt per learner and assessment is enforced by the generated `attempts.active_key` column: catch the unique-constraint error and return 409.
- Use `App\Services\Adaptive\Evidence\EvidenceAssembler` + `App\Services\Adaptive\Calculation\TopicAssessor` to get a topic's results; do not query marks directly in controllers.

## 9. API contract: practice quizzes (for Antigravity)

Controllers stay thin: call `App\Services\Adaptive\Quiz\AttemptService` and return its output. Its exceptions
(`App\Services\Adaptive\Quiz\Exceptions\*`) render their own JSON and status codes, so do not catch them.
All routes use `auth:sanctum`. Never use route-model binding for attempts; load them with
`AttemptService::findForLearner($request->user(), $id)` (other learners get 404).

| Method and route | Service call | Success response |
|---|---|---|
| `POST /api/me/assessments/{assessmentId}/attempts` | `start($user, $assessment)` | 201, `LearnerAttemptView::make($attempt)` |
| `GET /api/me/attempts/{attemptId}` | `findForLearner($user, $id)` | 200, `LearnerAttemptView::make($attempt)` |
| `PUT /api/me/attempts/{attemptId}/answers/{questionId}` body `{"option_ids": ["a"]}` | `saveAnswer($user, $attempt, $questionId, $body)` | 200, `{"saved": true}` |
| `POST /api/me/attempts/{attemptId}/submit` body `{"confirm_unanswered": bool}` | `submit($user, $attempt, $confirm)` | 200, `$result->toLearnerArray()` |

Errors returned by the service: 404 not yours / hidden revision quiz; 409 attempt already in progress
(`attempt_id` included), already submitted, quiz closed; 422 invalid answer, unusable quiz, or
`{"unanswered": n, "requires_confirmation": true}` — show SubmitAttemptDialog and resend with
`confirm_unanswered: true`.

`LearnerAttemptView` and `toLearnerArray()` never contain answer keys. Do not add fields from
`questions.answer_data` or `attempt_items.marking_snapshot` to any learner response.

## 10. API contract: courses, topic performance and Canvas sync (for Antigravity)

Use `App\Services\Adaptive\CourseAccess` for every course check (Canvas roles, v0.8 D12): a learner must be
`isStudent`, a lecturer `isLecturer`; otherwise return 404. Do not use `users.role` alone for course access.

| Method and route | Service call | Success response |
|---|---|---|
| `GET /api/me/courses` | `CourseAccess::coursesFor($user)` | 200, `[{id, name, code}]` |
| `GET /api/me/courses/{courseId}/topics` | if `CanvasSyncService::needsSync()` then `syncLearnerCourse()` (ignore `CanvasNotConnected`), then `TopicPerformanceService::forLearnerCourse($user, $course)` | 200, `{"data": [...]}` — each item is the §13.4 shape |
| `POST /api/me/courses/{courseId}/sync` | `CanvasSyncService::syncLearnerCourse($user, $course)` | 200, `{created, unchanged, skipped, flagged}` |
| `GET /api/me/courses/{courseId}/practice` | assessments of the course with `assessment_type = practice` and `status = open` | 200, `[{id, title, topic}]` |

`TopicPerformanceService` output already contains labels, explanations, confidence reason texts and conflict
pairs with source labels. Display it as is; do not recompute percentages or statuses in React. Show
`performance_display` exactly (it is already rounded down, C13). Never present coverage as a fraction of topic size.

**Demo data:** `php artisan migrate:fresh --seed` loads two courses linked to the Canvas fixtures
(`database/fixtures/canvas`), 28 questions, practice sets and three learners (password `password123`):
alex.morgan (strong), jamie.lee (needs help, conflict, pending grade), sam.patel (little evidence).

## 11. API contract: recommendations, goals and revision quizzes (for Antigravity)

Services: `App\Services\Adaptive\Recommendations\{RecommendationQuery, RecommendationActions}`,
`App\Services\Adaptive\GoalService`, `App\Services\Adaptive\Quiz\RevisionQuizService`.
Resolve them from the container (`app(...)` or constructor injection). Their exceptions render their own JSON.
Check course/topic access with `CourseAccess::isStudent()` before calling them (404 otherwise).

| Method and route | Service call | Success response |
|---|---|---|
| `GET /api/me/recommendations?state=active\|accepted\|postponed\|dismissed\|history` | `RecommendationQuery::section($user, $state)` | 200, `{"data": [...]}` |
| `GET /api/me/recommendations/shortlist` | `RecommendationQuery::shortlist($user)` | 200, `{"data": [...]}` (top 5 active) |
| `POST /api/me/recommendations/{id}/accept` | `RecommendationActions::accept($user, $id)` | 200, `RecommendationPresenter::toArray($rec)` |
| `POST /api/me/recommendations/{id}/postpone` body `{"reminder_at": "ISO 8601 with offset"}` | `postpone($user, $id, $reminderAt)` | 200, presenter output |
| `POST /api/me/recommendations/{id}/dismiss` | `dismiss($user, $id)` | 200, presenter output |
| `POST /api/me/recommendations/{id}/restore` | `restore($user, $id)` → `{recommendation, restored, message}` | 200, `{"data": presenter(recommendation), "restored": bool, "message": string}` |
| `GET /api/me/goals` | `GoalService::forLearner($user)` | 200, `[{id, topic: {id, name}, goal_type}]` |
| `POST /api/me/topics/{topicId}/goals` / `DELETE` same | `GoalService::add/remove($user, $topic, GoalType::Mastery)` | 201 / 204 |
| `POST /api/me/topics/{topicId}/help-requests` / `DELETE` same | `GoalService::add/remove($user, $topic, GoalType::Help)` | 201 / 204 |
| `POST /api/me/topics/{topicId}/revision-quiz` | `RevisionQuizService::createAndStart($user, $topic)` | 201, `LearnerAttemptView::make($attempt)` |
| `GET /api/me/courses/{courseId}/diagnostic-prompt` | `RecommendationEngine::showDiagnosticPrompt($user, $course)` | 200, `{"show": bool}` |

Each recommendation item has: `id`, `topic {id, name}`, `state`, `priority` (high|medium|low|optional),
`primary_reason`, `reason_codes`, `reasons` (sentences to display), `suggested_action {key, text}`
(key: revise_topic | revision_quiz | request_help | diagnostic), `performance {status, label,
performance_display, earned_marks, max_marks}`, `reminder_at`, `created_at`.

Recommendations page: sections Active, Accepted, Postponed, Dismissed and History. Every active card offers
**Accept**, **Remind me later** (date-time picker; future, max 60 days) and **Never again**. Dismissed cards
offer **Restore**; when `restored` is false show `message`. Never change state any other way: the services
write the history and enforce one open recommendation per topic.

## 12. API contract: lecturer tools (for Antigravity)

All lecturer services check `CourseAccess::isLecturer()` themselves and throw `LecturerException` (404 for
other courses, 422 with `errors: [...]` for invalid input). Let the exceptions render.
Services: `App\Services\Adaptive\Lecturer\{QuestionBankService, KlenamImportService, CanvasMappingService, ClassOverviewService}`.

| Method and route | Service call | Success response |
|---|---|---|
| `GET /api/courses/{courseId}/topics` | for each topic: `QuestionBankService::topicSummary($user, $topic)` | 200, topics with `topic_size`, `active_questions`, `warnings` |
| `GET /api/topics/{topicId}/questions` | topic questions **with** `answer_data` visible (`$q->makeVisible('answer_data')`) — lecturer routes only | 200 |
| `POST /api/topics/{topicId}/questions` | `QuestionBankService::create($user, $topic, $body)` | 201 |
| `PATCH /api/questions/{questionId}` | `update($user, $question, $body)` | 200 |
| `POST /api/questions/{questionId}/archive` | `archive($user, $question)` | 200 |
| `POST /api/questions/{questionId}/regrade` body `{"answer_data": {...}}` | `regrade($user, $question, $answerData)` | 200, `{regraded, unchanged}` |
| `POST /api/topics/{topicId}/questions/import` multipart `file` (+ `format` json\|csv) | `KlenamImportService::import($user, $topic, $contents, $format)` | 200, `{imported: [ids], rejected: [{row, reason}]}` |
| `GET /api/courses/{courseId}/canvas-items` | `CanvasMappingService::items($user, $course)` | 200 |
| `PUT /api/courses/{courseId}/canvas-mappings` body `{"mappings": [...]}` | `save($user, $course, $body['mappings'])` | 200, updated items |
| `GET /api/courses/{courseId}/overview` | `ClassOverviewService::forCourse($user, $course)` | 200, `{topics: [{topic_name, counts}], learners: [...]}` |
| `GET /api/courses/{courseId}/learners/{learnerId}` | `learnerDetail($user, $course, $learnerId)` | 200, `{learner, topics}` (§13.4 shape) |

Question body: `{question_type, question_text, difficulty, max_marks, answer_data: {options: [{id, text}],
correct_option_ids: [...]}, explanation}`. Once a question has been used, the edit form must disable
`max_marks`, `question_type` and the options; offer **Regrade** for answer-key corrections.
Mapping entry: `{canvas_assignment_id, canvas_criterion_id ('' = whole item), topic_id (null = unmap), difficulty}`.
Never show learners `topic_size`; it is lecturer information only (T2).
