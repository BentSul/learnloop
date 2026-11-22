# LearnLoop Adaptive Engine — v0.8 Amendment: Canvas + Klenam AI Extension

**Base specification:** v0.7 (team baseline)
**Version:** 0.8 (amendment)
**Status:** Draft — decisions marked **[Decision]** need team confirmation
**Deadline context:** Project due first week of December 2026 (≈ 2 weeks)

---

## 1. Product change

LearnLoop becomes an **adaptive-learning extension for Ashesi's Canvas**, also drawing on **Klenam AI**.

| Source | What it gives LearnLoop |
|---|---|
| **Canvas** (Ashesi's real instance) | Courses, assignments, quizzes, rubrics, and learners' scores |
| **Klenam AI** | AI-generated questions from lecturers' reading materials; viva quizzes on learners' project code |
| **LearnLoop** | Its own practice quizzes, plus the adaptive engine (performance, confidence, conflicts, recommendations) |

LearnLoop's purpose is unchanged: show learners where they stand per topic and recommend what to practise next.

### 1.1 What stays from v0.7

Everything in the calculation and recommendation layers stays unchanged:
- Performance, coverage, statuses, display rounding (§5; T4, C13)
- Difficulty, confidence (§7)
- Pairwise conflict rules (§8; T1). The group definitions change (§6 below)
- Goals, help requests, recommendations, Never again (§9–§10; T3)
- Evidence keys, versioning, idempotency, current-evidence selection (§4, §6.6), extended to Canvas and Klenam sources
- Security principles (§14)

### 1.2 Removed

| Removed | Replacement |
|---|---|
| SmartMark integration (§12), Roles 4 and 5's SmartMark scope | Canvas and Klenam adapters (§3, §4 below) |
| D11 (SmartMark contract) | D14, D15 below |
| `assignment_results`, `assignment_criteria` tables | `external_results`, `external_result_items` (§7 below) |

---

## 2. Architecture

```
          Canvas (Ashesi)                 Klenam AI
               │                              │
     CanvasGateway interface        KlenamImporter interface
   ┌───────┼────────────┐          ┌────────┴─────────┐
 OAuth/LTI  Token    Fixture     File import     API (if one exists)
 (target)  (fallback) (demo/tests)
               │                              │
               └──────────► External evidence ◄┘
                                   │
            LearnLoop practice quizzes ──► Evidence assembly (§6.6)
                                   │
          Performance · Confidence · Conflicts · Recommendations
```

Every external system sits behind an interface. The engine never calls Canvas or Klenam directly, so a fixture adapter can replace either one for demos and tests.

---

## 3. Canvas integration

### 3.1 Access path

Connecting to Ashesi's real Canvas needs institutional permission. Three adapters share one `CanvasGateway` interface:

| Tier | Adapter | Requires | Use |
|---|---|---|---|
| 1 — Target | **OAuth2 developer key** (+ LTI 1.3 launch later) | Ashesi Canvas admin creates a developer key; IT/registrar approval to read student grades | Production |
| 2 — Fallback | **User access token** | Each user generates a personal access token in Canvas (Account → Settings → Approved Integrations), if Ashesi allows it | Pilot with real data, own grades only |
| 3 — Always | **Fixture adapter** | Nothing | Development, automated tests, guaranteed demo |

**[Decision D15]** Which tier is used for the December demo. Recommendation: build Tier 3 first, then Tier 2; request Tier 1 from Ashesi IT now but do not depend on it before the deadline.

Rules for Tiers 1–2:
- Tokens are stored encrypted (Laravel `encrypted` cast), never logged, never returned by any API, and can be disconnected by the user (token deleted).
- A learner's token only reads that learner's own data. A lecturer's token reads the course data needed for mapping (§3.3).
- Real student grades are personal data. Before using real Ashesi data, get the course lecturer's and Ashesi IT's approval; read only the fields listed in §3.2.

### 3.2 Data read from Canvas

| Canvas object | Fields used |
|---|---|
| User | Canvas user ID, name |
| Course | Canvas course ID, name, code |
| Assignment (includes quizzes) | ID, name, points possible, due date, submission types, rubric (criteria IDs, descriptions, points) |
| Submission | Assignment ID, user ID, score, workflow state, excused, submitted at, graded at, posted at, attempt, rubric assessment (points per criterion) |

Question-level quiz data and Canvas Outcomes are not used in v1.

### 3.3 Topic mapping

Canvas has no LearnLoop topics or difficulty. Lecturers map Canvas items in LearnLoop's **Canvas mapping** screen:

| Mapping type | Evidence produced | Use when |
|---|---|---|
| **Whole item → one topic** | One evidence item: score / points possible | The assignment or quiz covers one topic |
| **Rubric criteria → topics** | One evidence item per mapped criterion: criterion points / criterion max | The assignment covers several topics and has a rubric |

- Each mapped item or criterion also gets a difficulty, default `medium` (same rule as D10).
- Unmapped items produce no evidence and are listed as "Not mapped" for the lecturer.
- Criteria mapping needs a rubric assessment on the submission. A graded submission without one is excluded and flagged to the lecturer.

**[Decision D13]** When a lecturer changes a mapping, existing evidence from that item is **re-derived** with the new topic and difficulty, and affected learners' recommendations are reconciled. Mapping changes are logged. This differs from the snapshot rule for LearnLoop quiz answers (§4.4), because a mapping is lecturer configuration rather than what the learner saw.

### 3.4 Eligibility (equivalent of "approved")

| Canvas submission | Treatment |
|---|---|
| Score present, graded, **posted** to the student | Eligible evidence |
| Score present but **not posted** | Not eligible; counts in `pending_count` ("Awaiting marking") |
| **Excused** | Excluded |
| Missing with no score | Excluded |
| Missing with score 0 entered | Eligible (0) |
| Score above points possible (extra credit) | Capped at points possible for LearnLoop calculations |

### 3.5 Evidence keys and versions

| Evidence | Key |
|---|---|
| Whole Canvas item | `c:{canvas_course_id}:{assignment_id}` |
| Canvas rubric criterion | `c:{canvas_course_id}:{assignment_id}:{criterion_id}` |

- Canvas holds one current grade per learner per assignment. Each sync stores a new **version** only when the score, rubric points or posted state changed. Old versions are kept (§4.4 principle).
- Version order is the order observed by LearnLoop's sync; the current version is the latest one.
- Evidence date (recency, "Last assessed") is the submission's `submitted_at`, or `graded_at` if there is no submission time.

### 3.6 Sync

- Runs when a user opens LearnLoop if the last sync for that user and course was more than 15 minutes ago, plus a **Sync now** button.
- Runs as one transaction per learner and course. A failed sync changes nothing and shows "Last synced at …".
- Respects Canvas rate limiting: on throttling, back off and retry later; never retry in a tight loop.
- After a sync that changes evidence, the learner's recommendations are reconciled (§10.7).
- No webhooks or scheduled jobs in v1.

### 3.7 Identity and courses

- A LearnLoop user is linked to one Canvas user ID (from the connected account).
- A LearnLoop course is linked to one Canvas course ID. Topics belong to the LearnLoop course.
- Lecturer access to a course's mapping and learner summaries requires the lecturer to be a teacher in that Canvas course (checked through the gateway), replacing the course-staff data previously expected from Role 1 (D12).

---

## 4. Klenam AI integration

Klenam AI generates questions from lecturers' reading materials and runs viva quizzes on learners' project code. Its data access is unknown.

### 4.1 Klenam as a question source (v1)

The strongest fit: Klenam-generated questions feed LearnLoop's **practice quizzes**.
- Lecturers import Klenam questions into LearnLoop's question bank by file import (JSON/CSV, format to be fixed once a real Klenam export is seen) or by entering them manually.
- Each imported question is tagged with one topic and a difficulty, as for any LearnLoop question (§3.2 of v0.7), and records `source = klenam`.
- Only objective questions (MCQ, True/False) are imported for v1.

### 4.2 Klenam as an evidence source (pending)

If Klenam records learners' quiz or viva **scores** and can export them, results are imported as external evidence:
- Source group `klenam` (§6 below).
- Viva results: the lecturer selects which topic(s) the viva assesses; one evidence item per topic, key `k:{klenam_quiz_id}:{topic_id}`.
- Only lecturer-confirmed viva scores are eligible.

**[Decision D14]** Whether Klenam scores are used at all, and how they are obtained (API, export file or manual entry). Blocked until the team confirms what Klenam can export.

---

## 5. LearnLoop's own quizzes

LearnLoop keeps its own quizzes so learners can practise.

**[Decision D16] Recommended for the December scope:**
- LearnLoop quizzes are **practice quizzes**: learner-started revision quizzes (§6.7 of v0.7) plus lecturer-built practice sets, both **objective only**.
- Written and graded work stays in Canvas. LearnLoop does **not** run open-ended questions, lecturer marking, approval or rejection in v1.
- Practice quizzes have no closing time, so auto-close (§6.4 closure, D8) is not needed in v1.
- Answer reveal: correct/incorrect per question after submission; explanations shown (D6 simplified).

Practice results remain evidence under the existing rules (latest attempt per question counts, blanks count as zero with warning, frozen attempt content).

---

## 6. Conflict groups (revision of D1)

D1 confirmed three groups (`auto_quiz`, `lecturer_quiz`, `assignment`). Those groups no longer match the sources.

**[Decision D1-R] Proposed three groups:**

| Group | Evidence |
|---|---|
| `learnloop` | LearnLoop practice quiz answers |
| `canvas` | All mapped Canvas items and criteria |
| `klenam` | Klenam results, if D14 enables them |

Everything else in D1 and T1 is unchanged: compare every available pair, a 30-point difference is a conflict, small-source conflicts are flagged and recommended, confidence is reduced only when both groups in that pair have ≥ 5 maximum marks, at most one level overall.

Alternative for discussion: split `canvas` into `canvas_quiz` (Canvas quizzes) and `canvas_assignment` (everything else), using the assignment's submission type. That gives up to four groups and six pairs.

Until D14 is decided, most learners have at most two groups (`learnloop`, `canvas`), so one pair.

---

## 7. Database changes

**Removed:** `assignment_results`, `assignment_criteria`.

**Added:**

**canvas_connections** — `id`, `user_id`, `canvas_user_id`, `adapter` (`oauth`, `token`, `fixture`), `access_token` (encrypted, nullable), `refresh_token` (encrypted, nullable), `expires_at`, `connected_at`, `revoked_at`

**course_links** — `id`, `course_id`, `canvas_course_id`, unique `(canvas_course_id)`

**canvas_item_mappings** — `id`, `course_id`, `canvas_assignment_id`, `canvas_criterion_id` (nullable = whole item), `topic_id`, `difficulty`, `mapped_by`, `updated_at`; unique `(course_id, canvas_assignment_id, canvas_criterion_id)`

**mapping_changes** — `id`, `mapping_id`, `old_topic_id`, `new_topic_id`, `old_difficulty`, `new_difficulty`, `changed_by`, `created_at`

**external_results** — `id`, `source` (`canvas`, `klenam`), `learner_id`, `course_id`, `external_item_ref`, `external_submission_ref`, `score_hash`, `posted` (bool), `submitted_at`, `graded_at`, `synced_at`, `version`

**external_result_items** — `id`, `external_result_id`, `evidence_key`, `topic_id`, `difficulty`, `earned_marks`, `max_marks`

**sync_runs** — `id`, `user_id`, `course_id`, `source`, `started_at`, `finished_at`, `status`, `error`

**questions:** add `source` (`learnloop`, `klenam`).

Version rule: a new `external_results` row is written only when `score_hash` (score + rubric points + posted state) differs from the latest version for that learner and item.

---

## 8. API changes

**Removed:** SmartMark integration endpoint (§12 of v0.7).

**Added (learner):**
- `POST /api/me/canvas/connect` — token or OAuth start
- `DELETE /api/me/canvas/connect` — disconnect and delete stored tokens
- `POST /api/me/courses/{courseId}/sync` — sync now; returns last sync status
- `GET /api/me/courses/{courseId}/sync-status`

**Added (lecturer):**
- `GET /api/courses/{courseId}/canvas-items` — Canvas assignments with rubric criteria and mapping state
- `PUT /api/courses/{courseId}/canvas-mappings` — create or update mappings (triggers re-derivation, D13)
- `POST /api/topics/{topicId}/questions/import` — Klenam question import (validation report: imported, rejected with reasons)

---

## 9. Implementation ownership (replaces individual roles)

Implementation is done by two AI coding agents — Claude and Antigravity — with the team directing, reviewing and deciding. References to Roles 1–5 elsewhere in v0.7 now mean "the owner of that area" below.

| Area | Owner | Why |
|---|---|---|
| Calculation core, evidence assembly, recommendation engine and reconciliation, their tests | Claude | Spec-heavy business logic |
| `CanvasGateway` and `KlenamImporter` interfaces, fixture adapter and sample data | Claude | Contracts both agents build against |
| API response contracts (§13.4 shapes) | Claude defines; Antigravity implements controllers | Keeps backend and React aligned |
| Project setup: API routes, Sanctum, users/roles, migrations running, database | Antigravity | Needs commands on the team's machine |
| React app (does not exist yet), learner and lecturer screens | Antigravity | Needs `npm` and a browser locally |
| Canvas token/OAuth adapters (real network calls) | Antigravity | Claude's environment cannot reach Ashesi's Canvas |
| Code review of each other's changes; spec and decision register | Claude | Single source of truth |

Coordination rules:
- One git repository. Each agent works on its own branch; the team merges.
- Shared files (`routes/api.php`, `bootstrap/app.php`, `composer.json`, `package.json`, `.env.example`) change only on the setup branch.
- Contracts first: interfaces and response shapes are merged before code that depends on them.
- `AGENTS.md` at the repository root records ownership, conventions and the current task list, so both agents read the same instructions.
- The team confirms decisions; agents do not change T1–T4 or confirmed decisions.

---

## 10. December scope

The full v0.7 specification is larger than two weeks of work for five people. Proposed split:

### 10.1 Build for December (MVP)

1. Canvas **fixture adapter** with realistic sample data (Web Tech and Python courses), then **token adapter** if Ashesi allows tokens.
2. Courses, topics, question bank (manual + Klenam file import), Canvas mapping screen.
3. Practice quizzes (objective only), frozen attempts, autosave, submit with blank warning.
4. Evidence assembly for LearnLoop + Canvas sources.
5. All calculations: performance, statuses (incl. `strong_unconfirmed`), round-down display, coverage, difficulty, confidence, pairwise conflicts.
6. Recommendations: priority rules, reconciliation, accept / remind later / Never again / restore, events, one-open-per-topic index.
7. Learner dashboard, topic detail, recommendations page; lecturer topic and mapping screens.
8. Automated tests for the calculation core and recommendation rules.

### 10.2 Defer (after December)

- LTI 1.3 launch inside Canvas and OAuth developer key (Tier 1)
- Open-ended questions, lecturer marking, approval/rejection (D5 remains confirmed for when it returns)
- Lecturer-run timed quizzes, auto-close (D8), regrade, voiding
- Klenam scores (D14) unless an export is available early
- Canvas Outcomes, question-level Canvas quiz data
- Advanced concurrency hardening beyond the unique index and transactions

**[Decision D17]** Confirm the December scope.

---

## 11. Decision register (new and changed)

| ID | Decision | Status | Recommendation |
|---|---|---|---|
| D1-R | Conflict groups after SmartMark removal | Needs team confirmation (replaces confirmed D1 groups) | `learnloop`, `canvas`, `klenam` |
| D11 | SmartMark contract | **Removed** | — |
| D12 | Course-staff and enrolment data | Changed | Read from Canvas (teacher/student role in the Canvas course) |
| D13 | Mapping changes re-derive evidence | Pending | Yes, with change log |
| D14 | Klenam scores as evidence | Blocked on Klenam export | Questions only for v1 |
| D15 | Canvas access tier for the demo | Pending | Fixture first, token if allowed, request OAuth key now |
| D16 | LearnLoop quizzes are objective practice only | Pending | Yes for v1 |
| D17 | December scope | Pending | §10.1 |

Unchanged and still binding: T1–T4, C13, D10 (now also the default difficulty for Canvas mappings), D5 (deferred with its feature, not changed). Still pending from v0.7: D2, D3, D4, D9.

---

## 12. Actions this week

1. **Ashesi IT / course lecturer:** ask for (a) a Canvas developer key for LearnLoop and (b) permission to read student grades for the pilot. Do this immediately; approval may take longer than the deadline.
2. **Canvas settings:** check whether Ashesi allows personal access tokens (Account → Settings → Approved Integrations → New Access Token).
3. **Klenam AI:** find out whether it exports questions or scores (file or API), and get one sample export.
4. **Team:** confirm D1-R, D13–D17 and the agent ownership split (§9).
5. **Environment:** git repository, database version (`php artisan db:show`), and a working `routes/api.php` + Sanctum setup (from the Stage 1 inspection).

---

**End of v0.8 Amendment.** Read together with the v0.7 baseline. Where they conflict, this amendment applies.
