# LearnLoop Adaptive Engine — v0.9 Implementation Notes

**Base documents:** v0.7 specification + v0.8 amendment (Canvas + Klenam)
**Version:** 0.9 (implementation notes)
**Purpose:** Record how the specification was implemented, corrections to the spec text, and decisions made during implementation that the team must confirm. Where this document and v0.7/v0.8 differ, this document describes what the code does.

Confirmed decisions T1–T4, C13, D1 (pairwise conflicts), D5 and D10 are implemented unchanged.

---

## 1. Corrections to the specification text

| Where | Spec says | Correct value | Why |
|---|---|---|---|
| v0.7 §8.3 example, §16.6 | Combined performance 24/35 = **68.6%** | **68.5%** | C13 rounds displayed values down: 24/35 = 68.57% → 68.5% |
| v0.7 §10.3 note | "Test 42 depends on this decision" | The §16.7 test for Strong-with-nothing | Test numbering changed between versions |

---

## 2. Decisions made during implementation (team to confirm)

| ID | Decision | Implemented as | Status |
|---|---|---|---|
| D1-R | Conflict source groups after SmartMark removal | `learnloop`, `canvas`, `klenam`, compared pairwise with T1 rules. The calculation code takes group names from the data, so changing the groups needs no code change. | Pending (default) |
| D13 | Lecturer changes a Canvas mapping | Existing evidence is re-derived as a **new version** on the learner's next sync (the version hash includes topic and difficulty). Unmapping an item records an empty version, so its evidence stops counting; history is kept. Every change is logged in `mapping_changes`. | Pending (default) |
| D18 (new) | Recommendation with the same signature as a previous one | **Dismissed** recommendations are never resurrected (T3). **Completed, obsolete and superseded** recommendations do **not** block a new recommendation with the same signature. Without this, a learner who completes the recommended practice but still needs help would never be recommended anything for that topic again. | **Needs confirmation** |
| D19 (new) | Klenam import file formats | JSON list (`question`, `type`, `options`, `answer` as letter / 1-based number / option text, `difficulty`, `marks`, `explanation`) or CSV (`question, option_a–d, answer, difficulty, marks, explanation`). Missing marks → 1; missing difficulty → medium (D10). Duplicates (same text in the topic) are rejected. | Provisional until a real Klenam export is seen (D14) |
| D20 (new) | Option shuffling | Options are shuffled in **every** LearnLoop quiz; question order is shuffled only in **revision** quizzes (practice sets keep the lecturer's order). | Pending (default) |

Still pending from earlier versions: D2 (Needs-help difficulty thresholds), D3 (Strong with nothing → no recommendation), D4 (flip-back creates a new recommendation), D6, D7, D8, D14–D17.

---

## 3. Implementation details worth knowing

### 3.1 Canvas (v0.8 §3)

- **Adapters:** `CanvasGateway` interface. `FixtureCanvasGateway` reads `database/fixtures/canvas/course-{id}.json` (Canvas API field names). Selected by `CANVAS_ADAPTER` (default `fixture`). Token/OAuth adapters are still to be written.
- **Eligibility:** posted grades count; unposted grades count as pending ("Awaiting marking"); excused work never counts; missing work with no score is skipped; extra credit is capped at points possible.
- **Mapping:** an item is mapped either as a whole or per rubric criterion. The mapping service rejects mixing both; if both somehow exist, sync uses the whole-item mapping. Criteria-mapped work graded without a rubric assessment is **flagged** in the sync summary.
- **Evidence date:** `submitted_at`, else `graded_at`, else the sync time.
- **Sync:** one transaction per learner and course; failures are recorded in `sync_runs` and change nothing. A learner is re-synced when the last successful sync is older than 15 minutes **or** a mapping changed since that sync (tracked by mapping-change ID, not time, so changes in the same second are not missed).
- **Course access (D12):** a Canvas `teacher` in the linked course is its lecturer; a Canvas `student` is a learner; admins see all courses. Users without an active Canvas connection see no courses.
- **Tokens:** `canvas_connections.access_token` / `refresh_token` are encrypted at rest and hidden from serialisation.

### 3.2 Practice and revision quizzes (v0.8 §5)

- Objective questions only (D16 default). Open-ended questions can exist in the bank but cannot be placed in a quiz.
- Starting an attempt freezes text, options, option order, topic, difficulty, max marks and the answer key (`attempt_items`).
- Submitting with blanks returns 422 `{unanswered, requires_confirmation: true}` until confirmed.
- Marking compares option IDs, all-or-nothing.
- Learners see score per question, correct/incorrect and the explanation, never the answer key (D6 simplified).
- Revision quiz: up to 10 active objective questions from the topic, preferring questions not answered in the last 7 days; at least 3 required (422 otherwise); visible only to its owner.

### 3.3 Question bank (v0.7 §3.2, §4.6)

- Once a question appears in any attempt: `max_marks`, `question_type` and options are locked; text, explanation, topic (same course only) and difficulty may change and affect only later attempts.
- **Regrade** may change option text and the correct answer but not add, remove or reorder options. It creates a new `auto` mark version (reason `regrade`) only where the mark changes, and updates the answer key of in-progress attempts. Affected learners' recommendations are reconciled.
- Topic summary (lecturer only): topic size, active questions, and the three warnings from §3.6.

### 3.4 Recommendations (v0.7 §10)

- Priority table, primary reason and signature exactly as §10.2–§10.6. Strong with nothing else → no recommendation (D3 default).
- Reconciliation runs on every recommendations read and after goal changes and regrades, under a per-learner lock, with one retry if the one-open-per-topic index is hit.
- Restore: becomes active only if the signature still applies **and** no other recommendation is open for the topic; otherwise it stays dismissed and the response explains why.
- Each recommendation includes reason sentences built from the values that triggered it, and a suggested action with a key for the UI: `revise_topic`, `revision_quiz`, `request_help`, `diagnostic`.
- Course-level "Take a diagnostic quiz" prompt: shown when an active topic is not assessed and has no goal or help request.

### 3.5 Numbers

- Marks are `DECIMAL(6,2)`, compared as integer hundredths.
- API numbers are JSON numbers. Values stored in JSON columns (recommendation `basis`) may come back as integers when whole (20.0 → 20); compare numerically, not by type.

---

## 4. Database (MariaDB 12.2.2, confirmed D9)

Tables added for Role 3: `courses`, `course_links`, `topics`, `questions`, `assessments`, `assessment_items`, `attempts`, `attempt_items`, `answers`, `mark_versions`, `canvas_item_mappings`, `mapping_changes`, `external_results`, `external_result_items`, `canvas_connections`, `sync_runs`, `learner_topic_goals`, `recommendations`, `recommendation_events`.

One-active-row rules use stored generated columns with unique indexes (MariaDB) and the same expression in SQLite for tests: one in-progress attempt per learner and assessment, one active goal per learner/topic/type, one open recommendation per learner and topic.

---

## 5. Implementation status

| Branch | Contents | Tests |
|---|---|---|
| `engine-core` (merged) | Calculation core | ✓ |
| `setup` (merged) | Sanctum API, roles, MariaDB | ✓ |
| `adaptive-schema` | Tables, models, evidence assembly | ✓ |
| `practice-quiz` | Attempt lifecycle and auto-marking | ✓ |
| `canvas-fixture` | Canvas gateway, sync, course access, topic performance response, demo data | ✓ |
| `recommendations` | Recommendation engine, goals, revision quizzes | ✓ |
| `lecturer-tools` | Question bank rules, regrade, Klenam import, Canvas mapping, class overview | ✓ (200 tests in total) |

Remaining: API controllers and React screens (Antigravity, per `AGENTS.md` §9–§12), the real Canvas token adapter, and running the migrations and demo seed on MariaDB after merging.
