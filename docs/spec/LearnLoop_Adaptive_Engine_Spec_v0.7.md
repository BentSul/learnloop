# LearnLoop Adaptive Engine Technical Specification v0.7

**Project:** LearnLoop **Module:** Adaptive Engine **Role:** Role 3
**Version:** 0.7 **Status:** Implementation baseline --- confirmed
decisions integrated; remaining provisional items are tracked in §17
**Technology direction:** Laravel, MySQL (or MariaDB --- see D9) and
React

------------------------------------------------------------------------

## 0. Changes from v0.6

### 0.1 Confirmed team decisions (unchanged)

These decisions are retained exactly as agreed and must not be altered
during implementation:

  -----------------------------------------------------------------------
  \#                      Decision                Where
  ----------------------- ----------------------- -----------------------
  T1                      A source difference of  §8
                          30+ percentage points   
                          is flagged even if a    
                          source has fewer than 5 
                          maximum marks.          
                          Confidence is reduced   
                          only when both compared 
                          sources have at least 5 
                          maximum marks.          
                          Additional practice or  
                          assessment is           
                          recommended in both     
                          cases.                  

  T2                      Topic size = total      §3.5, §7.1
                          maximum marks of active 
                          questions in the bank.  
                          Evidence coverage is    
                          calculated separately   
                          from current valid      
                          evidence and may exceed 
                          topic size. Topic size  
                          is never the coverage   
                          denominator.            

  T3                      Learners keep the       §10.8
                          **Never again** choice  
                          and can restore         
                          dismissed               
                          recommendations         
                          manually. Ordinary new  
                          evidence must not       
                          resurrect the same      
                          dismissed               
                          recommendation.         

  T4                      80%+ performance with   §5.6
                          fewer than 10 valid     
                          evidence marks gives    
                          `strong_unconfirmed`.   
                          The percentage is       
                          shown; mastery is not   
                          confirmed.              
  -----------------------------------------------------------------------

### 0.2 Confirmed decisions integrated in v0.7

  -----------------------------------------------------------------------
  ID                      Decision                Implementation
  ----------------------- ----------------------- -----------------------
  D1                      Three independent       §8.3
                          conflict groups:        
                          `auto_quiz`,            
                          `lecturer_quiz`, and    
                          `assignment`; compare   
                          every available pair.   

  C13                     Displayed percentages   §5.7
                          are always rounded down 
                          to one decimal place.   

  D5                      The same lecturer may   §4.5, §11.3
                          mark and approve, but   
                          marking and approval    
                          are separate explicit   
                          actions with separate   
                          actor and timestamp     
                          records.                

  D10                     Assignment criteria     §12.3
                          default to `medium`     
                          difficulty when         
                          SmartMark omits         
                          difficulty.             
  -----------------------------------------------------------------------

### 0.3 Additional implementation safeguards in v0.7

  -----------------------------------------------------------------------
  ID                      Change                  Section
  ----------------------- ----------------------- -----------------------
  I1                      Freeze question         §6.5, §11.2
                          metadata and            
                          learner-visible         
                          question content when   
                          an attempt starts, not  
                          only when it is         
                          finalised.              

  I2                      Enforce one open        §10.7, §11.5--§11.6
                          recommendation per      
                          learner/topic with a    
                          generated unique key,   
                          in addition to          
                          transactional           
                          reconciliation.         

  I3                      Serialize               §10.7--§10.8
                          recommendation          
                          reconciliation and      
                          state transitions;      
                          write state changes and 
                          event history in the    
                          same transaction.       

  I4                      Rename the evidence-age §7.7, §13.4, §15.2
                          date label to **Last    
                          assessed** because it   
                          is based on the newest  
                          current evidence item.  

  I5                      Expand conflict         §8, §13.4, §15.2, §16.6
                          explanations and tests  
                          to identify each        
                          disagreeing source      
                          pair.                   
  -----------------------------------------------------------------------

### 0.4 Corrections retained from v0.6

  -------------------------------------------------------------------------------------
  ID               Change                                              Section
  ---------------- --------------------------------------------------- ----------------
  C1               Every finalised answer gets a mark version that     §4.4, §11.3
                   **snapshots** topic, difficulty, max marks,         
                   evidence key and source type. Question edits never  
                   change history.                                     

  C2               `questions.max_marks` is immutable once a question  §3.2, §4.6
                   has been answered. Answer-key corrections go        
                   through a **regrade** action.                       

  C3               Stable assignment evidence key                      §6.2, §12
                   `a:{assignment_reference}:{criterion_reference}`;   
                   version ordering by highest `result_version`;       
                   resubmissions ordered by `submitted_at`.            

  C4               Auto-close defined (lazy, with optional command).   §6.4
                   `finalised_at` of an auto-closed attempt is the     
                   **closure time**, never the processing time. Late   
                   saves rejected.                                     

  C5               Each attempt freezes its question list and option   §6.5, §11.3
                   order (`attempt_items`). Options have stable IDs.   
                   Answers are upserted, one per attempt and question. 

  C6               Revision quizzes are modelled (`assessment_type`,   §6.7, §13
                   owner, topic) and have an endpoint.                 

  C7               Conflict source groups defined (default: quiz vs    §8.3
                   assignment).                                        

  C8               Provisional difficulty rule for Needs help.         §7.3
                   "Difficulty unavailable" shown to occur only with   
                   zero evidence.                                      

  C9               Recommendation priority defined as base-by-status   §10.3
                   plus modifiers, covering every case.                

  C10              Recommendation signature and reconciliation         §10.6--10.9
                   algorithm defined. Accepted → Obsolete/Superseded   
                   allowed. Restore rule made precise.                 

  C11              `recommendation_events` table added for full        §11.5
                   history.                                            

  C12              Lecturer access scoped to courses; marking,         §13.2, §14
                   approval, rejection, closing, voiding and regrade   
                   endpoints listed.                                   

  C13              Displayed percentages are rounded **down** to one   §5.7
                   decimal place. (8/9 now displays 88.8%.)            

  C14              Pending-marking count exposed; "Awaiting marking"   §5.6, §13.4
                   label when only pending answers exist.              

  C15              Answer-reveal policy defined.                       §6.8

  C16              SmartMark hardening: payload-hash mismatch → 409,   §12
                   timestamped HMAC, scoped token, response codes.     

  C17              Tie-breakers, concurrency rules, enrolment changes, throughout
                   archived content, revision-quiz minimums.           

  C18              Test suite expanded and made exact.                 §16
  -------------------------------------------------------------------------------------

------------------------------------------------------------------------

## 1. Purpose and overview

The LearnLoop Adaptive Engine evaluates learners' performance across
topics, tracks the evidence supporting those evaluations, estimates
confidence in the assessment, and generates personalised learning
recommendations.

It helps learners understand: - Which topics they understand well. -
Which topics need more attention. - How much assessment evidence
supports their current performance. - Whether their performance has been
demonstrated across difficulty levels. - What to study or practise next.

**Performance**, **evidence coverage** and **confidence** are separate
measurements and must never be treated as interchangeable.

### 1.1 Main responsibilities

Role 3 is responsible for:

1.  Managing topics and learning outcomes.
2.  Managing the question bank, including topic and difficulty tagging.
3.  Marking objective questions automatically.
4.  Receiving lecturer-approved marks for open-ended answers and
    assignments.
5.  Saving assessment attempts and individual answers.
6.  Calculating topic performance and evidence coverage.
7.  Calculating confidence in topic assessments.
8.  Detecting conflicting evidence.
9.  Generating and managing personalised recommendations.
10. Providing quiz, result, recommendation and lecturer management
    interfaces.

### 1.2 Out of scope

The Adaptive Engine does not: - Evaluate open-ended answers
automatically. - Replace lecturer approval of marks. - Manage the entire
course delivery process. - Own authentication, permissions, course-staff
or enrolment infrastructure. - Replace SmartMark's intake and marking
workflow. - Use AI prediction models in v1.

------------------------------------------------------------------------

## 2. Collaboration and dependencies

  -----------------------------------------------------------------------
  Role                    Responsibility          Dependency for Role 3
  ----------------------- ----------------------- -----------------------
  Role 1 --- Platform     Authentication,         Approves migrations.
  foundations             permissions, audit      **Provides course-staff
                          logs, shared database   and enrolment data**
                          changes                 used for authorisation
                                                  (§14).

  Role 2 --- Course       Learner course pages    Displays Adaptive
  delivery                                        Engine components;
                                                  hosts the
                                                  goal/help-request
                                                  interface.

  Role 4 --- SmartMark    Assignment intake       None directly; Role 3
  intake                                          consumes approved
                                                  results via Role 5.

  Role 5 --- SmartMark    Approved assignment     Sends results using the
  reporting               results                 contract in §12.
  -----------------------------------------------------------------------

------------------------------------------------------------------------

## 3. Topics and question bank

### 3.1 Topics

Each course contains topics; each topic has learning outcomes and a
status (`active`, `archived`).

Sample topics:

**Web Technology** --- HTML basics, CSS, JavaScript logic **Python** ---
Python basics, Functions, Lists, Dictionaries

When a topic is archived: - It is hidden from learner dashboards and
topic lists. - Its open recommendations become `obsolete`. - Its
evidence is retained.

### 3.2 Questions

Each question has: - Question ID and Topic ID (course is derived from
the topic, not stored separately) - Question text - Question type:
`mcq`, `true_false`, `open_ended` - Difficulty: `easy`, `medium`,
`hard` - Maximum marks - Answer data: options with **stable option IDs**
and the correct option ID(s), where applicable - Marking reference
(open-ended) - Explanation or feedback - Status: `active`, `inactive` -
Created and updated timestamps

**Edit rules once a question has at least one answer:**

  ---------------------------------------------------------------------
  Field                              Editable?
  ---------------------------------- ----------------------------------
  Question text, explanation         Yes (wording fixes)

  Topic, difficulty                  Yes --- affects **future** answers
                                     only (past evidence uses
                                     snapshots, §4.4)

  Maximum marks                      **No.** Archive the question and
                                     create a new one.

  Options and correct answer         Only through the **regrade**
                                     action (§4.6)
  ---------------------------------------------------------------------

An inactive question is excluded from topic size and new assessments,
but its past evidence still counts.

### 3.3 Topic tagging

In v1, every question belongs to exactly one topic. Multiple topic tags
are a future feature.

### 3.4 Difficulty levels

`easy`, `medium`, `hard`. Difficulty affects confidence only, never
performance.

### 3.5 Topic size (T2)

Topic size = total maximum marks of **active** questions in the topic's
bank.

-   Topic size is lecturer-facing information.
-   It is not the coverage denominator.
-   Approved assignment evidence can make a learner's coverage exceed
    topic size. This is expected.
-   **Never display coverage as a fraction of topic size** (e.g. never
    "24 of 20 marks").

### 3.6 Lecturer topic warnings

Shown to lecturers only: - "This topic has fewer than 10 available
question-bank marks. Approved assignment evidence may be needed before
mastery can be confirmed." - "This topic has no Hard questions. Learners
may have limited opportunities to demonstrate performance at the highest
difficulty level." - "This topic has fewer than 3 active objective
questions. Revision quizzes cannot be generated."

These are warnings, not restrictions.

------------------------------------------------------------------------

## 4. Marking and evidence

### 4.1 Objective questions

MCQ and True/False are marked automatically by comparing the selected
**option IDs** with the correct option IDs. Display order never affects
marking.

### 4.2 Open-ended questions

-   Marked by lecturers. Role 3 never evaluates written answers.
-   An answered open-ended response contributes only once its mark is
    approved.
-   A **blank** open-ended response counts as zero immediately at
    finalisation; no approval needed.

**Blank** means: no option selected (objective), or empty /
whitespace-only text (open-ended).

### 4.3 Source types

  Source type       Meaning
  ----------------- --------------------------------------------------------
  `auto_quiz`       Automatically marked objective answer, or blank answer
  `lecturer_quiz`   Lecturer-marked open-ended answer
  `assignment`      Lecturer-approved SmartMark criterion

### 4.4 Mark versions and snapshots

When an attempt starts, each `attempt_items` row freezes question
metadata and learner-visible content (§6.5). When the attempt is
finalised (§6.4), **every frozen item in the attempt** receives an
answer row (blank if unanswered) and a first mark version:

  ---------------------------------------------------------------------
  Answer                             Version 1 status
  ---------------------------------- ----------------------------------
  Objective (answered or blank)      `auto`

  Open-ended, blank                  `auto` (0 marks)

  Open-ended, answered               No version until a lecturer marks
                                     it; then `pending`
  ---------------------------------------------------------------------

Each mark version **copies its evidence metadata from the attempt-time
snapshot**, not from the live question row: `topic_id`, `difficulty`,
`max_marks`, `evidence_key`, `source_type`, `finalised_at` (copied from
the attempt).

Snapshots mean later edits to question text, options, topic, difficulty
or answer configuration cannot change what the learner saw or silently
alter existing evidence. Objective regrading remains an explicit
versioned action (§4.6).

### 4.5 Version lifecycle

Mark version statuses: `auto`, `pending`, `approved`, `rejected`.

-   A lecturer enters a mark → new version, `pending`.
-   Approval → `approved`. Records `approved_by` and `approved_at`.
-   Rejection → `rejected`, with `rejection_reason`. The answer returns
    to the marking queue.
-   A change to an approved mark creates a **new** version (`pending`).
    The previous approved version keeps counting until the new one is
    approved.
-   Versions are never edited or deleted, except the status change
    `pending → approved/rejected`.

**Current version of an answer** = the highest-numbered version with
status `auto` or `approved`. If none exists, the answer contributes
nothing.

Version numbers are assigned inside a transaction;
`(answer_id, version)` is unique.

**\[D5 --- CONFIRMED\]** The same lecturer may both mark and approve an
answer. These remain two separate explicit actions: - Marking creates a
`pending` version and records `marked_by` and `marked_at`. - Approval is
a separate request/action that changes that version to `approved` and
records `approved_by` and `approved_at`. - The two actions must be
independently auditable even when the actor is the same person. The
mark-entry endpoint must never trigger approval implicitly. - Role 1's
audit log records both actions.

### 4.6 Regrade (answer-key correction)

If an objective question's correct answer was wrong, a lecturer uses
**Regrade question**:

1.  The corrected answer data is saved.
2.  For every answer to that question in a finalised, non-voided
    attempt, a new `auto` version is created with the recomputed mark
    (reason `regrade`) **only if the mark changes**.
3.  Old versions are kept.
4.  Affected learners' recommendations are reconciled (§10.7).

------------------------------------------------------------------------

## 5. Performance

### 5.1 Exact arithmetic

Marks are stored as `DECIMAL(6,2)`. Internally, all comparisons use
**integer hundredths**:

`(int) round($marks * 100)`

Validation: `max_marks > 0`, `0 ≤ earned_marks ≤ max_marks`.

### 5.2 Current evidence set

All performance, coverage, confidence and conflict calculations use the
learner's **current evidence set** for the topic (§6.6). Each evidence
key contributes at most once.

### 5.3 Performance formula

**P = (Total earned marks / Total maximum marks) × 100**, over the
current evidence set.

Mark-weighted, not an average of percentages. Quiz and assignment
evidence are combined.

  Item                     Earned   Maximum
  ---------------------- -------- ---------
  Question A                    4         5
  Question B                    3         5
  Assignment criterion          7        10
  **Total**                **14**    **20**

P = 14 / 20 × 100 = 70%.

### 5.4 Coverage

Coverage = total maximum marks of the current evidence set.

### 5.5 Statuses (T4)

  -------------------------------------------------------------------------
  Internal status         Rule (exact comparisons)    Learner-facing label
  ----------------------- --------------------------- ---------------------
  `not_enough_evidence`   Current evidence set is     "Not assessed", or
                          empty                       "Awaiting marking" if
                                                      `pending_count > 0`

  `needs_help`            `earned × 100 < 50 × max`   Needs help

  `getting_there`         Not below 50% and           Getting there
                          `earned × 100 < 80 × max`   

  `strong_unconfirmed`    `earned × 100 ≥ 80 × max`   Strong so far ---
                          and coverage \< 10          more evidence needed

  `strong`                `earned × 100 ≥ 80 × max`   Strong performance
                          and coverage ≥ 10           
  -------------------------------------------------------------------------

For `not_enough_evidence`, no percentage is calculated (no 0 ÷ 0).

### 5.6 Strong so far (T4)

A learner with 8/9 marks sees: - "88.8% --- Strong so far" - "More
assessment evidence is needed before topic mastery can be confirmed."

They are never labelled as having confirmed Strong mastery.

### 5.7 Display rounding

The displayed percentage is **rounded down** to one decimal place:

`display = floor(earned × 1000 / max) / 10`

Rounding down guarantees a displayed value never reaches a threshold the
exact value has not reached (e.g. 19.99/25 = 79.96% displays as 79.9%,
consistent with Getting there).

### 5.8 Pending marking

`pending_count` = number of answers in the learner's finalised,
non-voided attempts for the topic whose highest version is `pending` or
`rejected`, or which are answered open-ended items with no version yet.

It is returned with every performance result and shown to the learner.

------------------------------------------------------------------------

## 6. Attempts, evidence selection and retakes

### 6.1 Attempt statuses

  Status          Meaning                           Counts?
  --------------- --------------------------------- ---------
  `in_progress`   Learner is answering              No
  `submitted`     Finalised by the learner          Yes
  `auto_closed`   Finalised by assessment closure   Yes
  `voided`        Invalidated by a lecturer         No

-   At most one `in_progress` attempt per learner per assessment
    (§11.6).
-   If the latest attempt is voided, the previous eligible attempt
    becomes the current contribution again automatically.

### 6.2 Evidence keys

  ------------------------------------------------------------------------------------
  Evidence         Key                                                Shared across
  ---------------- -------------------------------------------------- ----------------
  Quiz question    `q:{question_id}`                                  All assessments,
                                                                      including
                                                                      revision quizzes

  Assignment       `a:{assignment_reference}:{criterion_reference}`   All versions and
  criterion                                                           resubmissions of
                                                                      that assignment
  ------------------------------------------------------------------------------------

Quiz and assignment keys never overlap; their evidence is combined,
never superseded by each other.

### 6.3 Ordering rules

Two separate orderings apply:

1.  **Which answer counts for a quiz key:** among answers that have a
    current version (§4.5), the one with the greatest
    `(finalised_at, attempt_id)`.
2.  **Which mark counts for that answer:** its current version (§4.5).

Consequences: - Re-marking an older attempt never makes it replace a
newer attempt. - A pending or rejected answer in a newer attempt never
replaces an earlier eligible answer. - Two attempts finalised in the
same second are ordered by attempt ID.

For assignments: 1. Within a submission, the current result is the
**highest `result_version`**, regardless of arrival order. 2. Across
submissions of the same assignment, the latest is chosen by
`(submitted_at, assignment_result_id)`.

### 6.4 Submission and closure

**Submission** (learner action): - Runs inside a database transaction
with `lockForUpdate()` on the attempt. - Proceeds only if the status is
still `in_progress`; a second submission returns 409. - If blank items
exist and `confirm_unanswered` is not true, returns 422 with
`{ "unanswered": n, "requires_confirmation": true }`. - Sets
`submitted_at = finalised_at = now`, status `submitted`, and creates
answers and mark versions per §4.4.

**Closure** (assessment-level event): - An assessment closes when a
lecturer calls the close endpoint (sets `closed_at`, `closed_by`),
**or** when `closes_at` passes. - v1 uses **lazy closure**: before any
attempt read, answer save, attempt start or performance calculation for
a learner, all of that learner's `in_progress` attempts on closed
assessments are finalised as `auto_closed`. - **`finalised_at` of an
auto-closed attempt = the assessment's closure time** (`closed_at` if
closed manually, otherwise `closes_at`), never the time the lazy process
runs. - After closure, answer saves and attempt starts return 409. - An
optional `php artisan attempts:auto-close` command performs the same
work if a scheduler is available.

**\[D8\]** Default: lazy closure only for v1.

Example: Quiz A closes Monday with Q12 blank in an in-progress attempt.
On Wednesday the learner answers Q12 correctly in a revision quiz. When
lazy closure runs on Friday, the Quiz A attempt gets
`finalised_at = Monday`, so Wednesday's correct answer remains current.

### 6.5 Frozen attempt content

When an attempt starts, its complete question set and the content needed
to render and mark each item are snapshotted into `attempt_items`.

Each `attempt_items` row stores at minimum: - `question_id` and
`position` - `question_type_snapshot` - `question_text_snapshot` -
`topic_id_snapshot`, `difficulty_snapshot` and `max_marks_snapshot` -
`option_order` and a snapshot of option IDs and learner-visible option
text - A protected answer-key/marking-configuration snapshot or
immutable question-version reference, accessible only to marking
services and never returned by learner endpoints - A question
content/version identifier for traceability

Rules: - The learner sees exactly the frozen content, including after a
page refresh. - The topic, difficulty and maximum marks used for
evidence come from the attempt-time snapshot. - Later edits to the
question bank or assessment affect only new attempts. - Only items in
`attempt_items` count at finalisation. A question added to the
assessment mid-attempt is not part of that attempt. - Changes to the
live question's answer key do not silently alter an existing attempt.
Corrections use the explicit regrade workflow (§4.6). - Lecturers
editing an open assessment with in-progress attempts see a warning.

Answers are saved by upsert, unique per `(attempt_id, question_id)`.
Saves are rejected (409) unless the attempt is `in_progress` and the
assessment is open.

### 6.6 Current evidence assembly

    function currentEvidence(learnerId, courseId, now):
        autoCloseExpiredAttempts(learnerId, courseId, now)                 // §6.4

        // Quiz evidence
        rows = []
        for each answer in attempts of learnerId in courseId
                with attempt.status in ('submitted', 'auto_closed'):
            v = highest version of answer with status in ('auto', 'approved')
            if v exists:
                rows.add(v, attempt.finalised_at, attempt.id)
        quizCurrent = per evidence_key: row with greatest (finalised_at, attempt_id)

        // Assignment evidence
        results = assignment_results of learnerId in courseId
        current = per submission_reference: result with highest result_version
        criteria = criteria of current results
        assignmentCurrent = per evidence_key: row with greatest (submitted_at, assignment_result_id)

        return quizCurrent ∪ assignmentCurrent

Load all of a learner's eligible rows for a course in one query and
group in the service layer (no N+1 queries).

### 6.7 Retakes and revision quizzes

When a learner finalises a new attempt: - Each item replaces the
previous result for its evidence key, subject to §6.3. - Blank items
count as zero and also replace previous results (with the mandatory
warning in §6.4). - Earlier attempts and versions remain in history.

**Revision quizzes:** - Created by the learner for one topic. Stored as
an assessment with `assessment_type = revision`, `owner_learner_id`,
`topic_id`. - Visible only to their owner. - **\[D7\]** Default:
objective questions only (immediate results, no marking load). - Size:
up to 10 questions from the topic's active objective questions; minimum
3 available, otherwise 422 with a clear message. - Selection prefers
questions the learner has not answered in the last 7 days. - Question
order and option order are shuffled (stored per attempt, §6.5). - No
`closes_at`. An unfinished revision attempt never counts and is never
auto-closed. - Completed revision attempts count as normal evidence.

The implementation must not assume repeated attempts alone provide
independent evidence of mastery. Cooldowns may be added later.

### 6.8 Answer reveal policy

**\[D6\]** Defaults:

  ---------------------------------------------------------------------
  Assessment type                    After finalisation the learner
                                     sees
  ---------------------------------- ----------------------------------
  `quiz`                             Their score per question
                                     immediately; correct answers and
                                     explanations **after the
                                     assessment closes**

  `revision`                         Correct / incorrect per question
                                     only; never the answer key
  ---------------------------------------------------------------------

Learner endpoints never return answer keys or marking references outside
this policy.

------------------------------------------------------------------------

## 7. Coverage, difficulty and confidence

### 7.1 Coverage bands

  Band     Coverage
  -------- ----------------------
  Low      Below 10 marks
  Medium   10 to below 20 marks
  Broad    20 marks or more

### 7.2 Difficulty classes (positive statuses)

For `getting_there`, `strong_unconfirmed` and `strong`, using **earned**
marks in the current evidence set:

  ---------------------------------------------------------------------
  Class                              Rule
  ---------------------------------- ----------------------------------
  Basic                              Medium + Hard earned \< 25% of
                                     total earned

  Challenging                        Medium + Hard earned ≥ 50% of
                                     total earned **and** Hard earned ≥
                                     15% of total earned

  Mixed                              Otherwise
  ---------------------------------------------------------------------

### 7.3 Difficulty classes (Needs help)

**\[D2\]** Provisional rule using **lost** marks (max − earned):

  Class         Rule
  ------------- -----------------------------------------
  Basic         Easy + Medium lost \< 25% of total lost
  Mixed         25% to below 50%
  Challenging   Easy + Medium lost ≥ 50% of total lost

Rationale: failing basic material is convincing evidence of difficulty;
failing only Hard questions is not.

On learner-facing Needs help cards, the difficulty class label is not
shown; the confidence reason text is shown instead (§7.6).

### 7.4 Difficulty is always available when evidence exists

-   Positive statuses imply earned \> 0.
-   `needs_help` (below 50%) implies lost \> 0.
-   Therefore a difficulty class exists whenever the evidence set is
    non-empty. With an empty set, confidence is unavailable. No
    "unavailable difficulty" column is needed.

### 7.5 Confidence

1.  If coverage \< 10 marks → **Limited** (firm safeguard).
2.  Otherwise, look up:

  Coverage   Basic      Mixed      Challenging
  ---------- ---------- ---------- -------------
  Medium     Moderate   Moderate   High
  Broad      Moderate   High       High

3.  Apply a conflict reduction if required (§8.4).

Confidence never changes performance or status. Zero evidence →
confidence unavailable.

### 7.6 Confidence reasons

Every confidence value is returned with reason codes and learner-facing
text, e.g.:

  Code                 Text
  -------------------- -----------------------------------------------
  `low_coverage`       "Fewer than 10 marks of evidence so far."
  `mostly_basic`       "Based mostly on easier questions."
  `hard_only_losses`   "Marks were mostly lost on harder questions."
  `conflict_reduced`   "Quiz and assignment results disagree."

### 7.7 Recency

Recency never reduces performance, status or confidence.

Evidence date: `finalised_at` (quiz) or `submitted_at` (assignment). Age
is measured in whole days in UTC from the **newest** item in the current
evidence set. The learner-facing date label is **Last assessed**,
because it reports the newest contributing evidence date rather than the
age of every item in the evidence set.

  Age                 Label
  ------------------- ----------
  0--30 days          Recent
  31--90 days         Older
  More than 90 days   Outdated

The evidence-age classification (`Recent`, `Older`, `Outdated`) is
displayed next to confidence, with the date itself labelled **Last
assessed**. Outdated Strong topics receive an optional revision
recommendation (§10.3).

Because recency depends on the current time, it is always computed at
read time and never cached.

------------------------------------------------------------------------

## 8. Conflicting evidence (T1)

### 8.1 Threshold

A conflict exists when compared source groups differ by **at least 30
percentage points**, using exact values:

`|e1 × m2 − e2 × m1| × 100 ≥ 30 × m1 × m2`

### 8.2 Minimum evidence (T1)

  -------------------------------------------------------------------------
  Both compared   Flag shown      Confidence      Practice recommended
  groups ≥ 5                      reduced         
  maximum marks?                                  
  --------------- --------------- --------------- -------------------------
  Yes             Yes             Yes (one level) Yes
                                                  (`conflict_sufficient`)

  No              Yes             No              Yes (`conflict_limited`)
  -------------------------------------------------------------------------

### 8.3 Source groups

**\[D1 --- CONFIRMED\]** Use three independent source groups: -
`auto_quiz` --- automatically marked objective quiz answers, including
blank objective answers counted as zero. - `lecturer_quiz` ---
lecturer-marked and approved open-ended quiz answers. - `assignment` ---
lecturer-approved SmartMark assignment criteria.

Compare every available pair: `auto_quiz` vs `lecturer_quiz`,
`auto_quiz` vs `assignment`, and `lecturer_quiz` vs `assignment`.

A pair is in conflict when its exact source-group percentages differ by
at least 30 percentage points (§8.1). Apply T1 separately to each
conflicting pair: - The pair is always flagged, including when either
group has less than 5 maximum marks. - Confidence is reduced only when
**both groups in that particular conflicting pair** have at least 5
maximum marks. - A small-source conflict still triggers an
additional-practice or assessment recommendation. - If several pairs
qualify for confidence reduction, reduce confidence by no more than one
level overall (§8.4).

Example: auto quiz 9/10 (90%), lecturer quiz 2/5 (40%), assignment 13/20
(65%): - `auto_quiz` vs `lecturer_quiz`: 50-point difference; both
groups have at least 5 marks, so flag and qualify for confidence
reduction. - `auto_quiz` vs `assignment`: 25-point difference; no
conflict. - `lecturer_quiz` vs `assignment`: 25-point difference; no
conflict.

The combined topic performance remains mark-weighted across **all**
current eligible evidence. Source groups are not averaged equally.
Conflict comparison uses the current evidence set only; groups with no
evidence are not compared.

### 8.4 Confidence reduction

At most one level overall, even if multiple source pairs qualify for
reduction: - High → Moderate - Moderate → Limited - Limited → Limited

### 8.5 Conflict explanation

Conflict information is computed at read time and returned with the
performance result: - Every pair compared, with the two source-group
names, earned/max marks and exact percentages - The exact
percentage-point difference for each pair - Whether each pair crossed
the 30-point conflict threshold - Whether both groups in each
conflicting pair met the 5-maximum-mark condition - Whether that pair
qualified for confidence reduction - Whether overall confidence was
reduced (at most once)

The recommendation's `basis` stores this snapshot when the
recommendation is created (§10.5).

------------------------------------------------------------------------

## 9. Learner goals and help requests

Learners can add or remove a mastery goal and request or withdraw help
for any topic, at any time.

-   Stored separately from evidence (`learner_topic_goals`, `goal_type`:
    `mastery`, `help`).
-   At most one active row per learner, topic and goal type (§11.6).
-   Never change performance, coverage, confidence or status.
-   Affect recommendation reasons and priority only.
-   Removal is a soft delete (`removed_at`), preserving history.

------------------------------------------------------------------------

## 10. Recommendation engine

### 10.1 Principles

-   Every recommendation explains why it exists and gives a practical
    next step.
-   At most **one open recommendation per topic** (open = `active`,
    `accepted`, `postponed`).
-   Performance figures are computed live; recommendation identity and
    state are stored.

### 10.2 Reason codes

  ---------------------------------------------------------------------
  Code                               Applies when
  ---------------------------------- ----------------------------------
  `needs_help`                       Status is `needs_help`

  `getting_there`                    Status is `getting_there`

  `strong_unconfirmed`               Status is `strong_unconfirmed`

  `help_requested`                   Learner has an active help request

  `mastery_goal`                     Learner has an active mastery goal

  `conflict_sufficient`              Conflict with both groups ≥ 5
                                     marks

  `conflict_limited`                 Conflict with a group below 5
                                     marks

  `evidence_outdated`                Status is `strong` and evidence
                                     age is Outdated
  ---------------------------------------------------------------------

### 10.3 Priority rules

Priority enum, highest first: `high`, `medium`, `low`, `optional`.

Each applicable reason contributes a priority; the recommendation takes
the **highest**. If no reason applies, no recommendation exists.

  ----------------------------------------------------------------------------------------------------------
  Reason                  needs_help   getting_there   strong_unconfirmed   strong     not_enough_evidence
  ----------------------- ------------ --------------- -------------------- ---------- ---------------------
  Status reason           High         Medium          Low                  ---        ---

  `help_requested`        High         High            Medium               Medium     Medium

  `mastery_goal`          High         Medium          Low                  Low        Low

  `conflict_sufficient`   High         High            High                 High       ---

  `conflict_limited`      Medium       Medium          Medium               Medium     ---

  `evidence_outdated`     ---          ---             ---                  Optional   ---
  ----------------------------------------------------------------------------------------------------------

Consequences: - Strong with no goal, no help request, no conflict and
recent evidence → **no recommendation**. **\[D3\]** (Alternative: an
Optional "maintain progress" recommendation. Test 42 depends on this
decision.) - Not assessed with no goal or help request → no per-topic
recommendation. Instead, the course topic list returns
`show_diagnostic_prompt: true` for one course-level "Take a diagnostic
quiz" prompt, which is not stored as a recommendation.

### 10.4 Primary reason and suggested action

The primary reason is the applicable reason with the highest priority;
ties are broken by the order in §10.2.

  ---------------------------------------------------------------------
  Primary reason                     Suggested action
  ---------------------------------- ----------------------------------
  `needs_help`                       Revise the topic, take a revision
                                     quiz, request help

  `getting_there`                    Take a revision quiz

  `strong_unconfirmed`               Complete another assessment to
                                     confirm mastery

  `help_requested`                   Guided revision and support for
                                     the topic

  `mastery_goal`                     Continued practice towards mastery
                                     (or "begin with an assessment" if
                                     not assessed)

  `conflict_sufficient` /            Additional practice or assessment;
  `conflict_limited`                 both group percentages shown

  `evidence_outdated`                Optional revision quiz
  ---------------------------------------------------------------------

Reason text is always generated from real values (e.g. "You scored 6/15
(40.0%) on Lists. You requested help with this topic.").

### 10.5 Recommendation record

Stored fields: `id`, `learner_id`, `topic_id`, `signature`,
`status_at_creation`, `primary_reason`, `reason_codes`, `priority`,
`basis`, `state`, `reminder_at`, `accepted_at`, `dismissed_at`,
`restored_at`, `completed_at`, `obsolete_at`, `superseded_at`,
`superseded_by_id`, timestamps.

`basis` (JSON) snapshots the values that explained the recommendation at
creation: performance, coverage, confidence, evidence age, conflict
details.

### 10.6 Signature

**Signature = topic ID + status + sorted reason codes.**

Example: `12|needs_help|help_requested,needs_help`

A change in percentage within the same status does **not** change the
signature. A change in status or reason codes does.

### 10.7 Reconciliation

Run for a learner on every recommendations read (including the dashboard
shortlist) and after any evidence change affecting them (finalisation,
approval, regrade, voiding, SmartMark result, goal change).

**Transaction and locking requirements (v0.7):** - Begin a database
transaction and lock the learner's row before reading or reconciling
that learner's recommendations. - Recompute the desired recommendation
state while holding that lock. - Lock any recommendation row before
changing its state. - Create/update the recommendation and insert its
`recommendation_events` row in the same transaction. - Rely on the
unique `open_topic_key` index (§11.6) as a final guard. Handle a
unique-key collision by rolling back and retrying from fresh state. -
Keep the transaction scoped to one learner; do not lock unrelated
learners' recommendation rows.

1.  **Due reminders:** postponed recommendations with
    `reminder_at ≤ now` become `active` if their signature equals the
    current desired signature, otherwise `obsolete`.
2.  **Completion first:** each `accepted` recommendation becomes
    `completed` if, after `accepted_at`, the learner finalised an
    attempt containing an item from that topic, or approved assignment
    evidence for that topic arrived. Completion is never treated as
    mastery.
3.  **Desired state:** for each active topic, compute the desired
    signature (or none).
4.  **Compare with the most recent recommendation for the topic** (by
    `created_at`, any state):

  ---------------------------------------------------------------------------------
  Most recent recommendation                  Desired            Action
  ------------------------------------------- ------------------ ------------------
  None                                        None               Nothing

  None                                        Signature S        Create `active`
                                                                 recommendation S

  Any state with signature S                  S                  Nothing (**a
                                                                 dismissed S is not
                                                                 resurrected ---
                                                                 T3**)

  Open (active/accepted/postponed) with       Different          Mark it
  signature S                                 signature S′       `superseded`;
                                                                 create S′

  Open with signature S                       None               Mark it `obsolete`

  Closed                                      Different S′       Create S′
  (dismissed/completed/obsolete/superseded)                      
  with signature S                                               
  ---------------------------------------------------------------------------------

5.  **Postponement carry-over:** if a `postponed` recommendation is
    superseded while its `reminder_at` is still in the future, the new
    recommendation is created as `postponed` with the same
    `reminder_at`.

**\[D4\]** Because step 4 compares with the most recent recommendation,
a learner who dismisses Needs help, moves to Getting there (new
recommendation), then drops back to Needs help, receives a new Needs
help recommendation. This treats the status change as "materially
different" under T3. Team to confirm.

### 10.8 States and transitions

States: `active`, `accepted`, `postponed`, `dismissed`, `completed`,
`obsolete`, `superseded`.

  -----------------------------------------------------------------------
  From                    Event                   To
  ----------------------- ----------------------- -----------------------
  ---                     Created by              `active` (or
                          reconciliation          `postponed`, §10.7 step
                                                  5)

  `active`                Accept                  `accepted`

  `active`                Remind me later         `postponed`

  `active`, `accepted`,   Never again             `dismissed`
  `postponed`                                     

  `postponed`             Reminder due, signature `active`
                          still desired           

  `postponed`             Reminder due, signature `obsolete`
                          no longer desired       

  `accepted`              Verified activity       `completed`
                          (§10.7 step 2)          

  `active`, `accepted`,   Desired signature       `superseded`
  `postponed`             changes                 

  `active`, `accepted`,   No recommendation       `obsolete`
  `postponed`             needed                  

  `dismissed`             Restore, and its        `active`
                          signature equals the    
                          current desired         
                          signature               

  `dismissed`             Restore, signature no   Stays `dismissed`;
                          longer desired          response explains it no
                                                  longer applies
  -----------------------------------------------------------------------

-   Repeating the action already applied (e.g. accepting an accepted
    recommendation) returns 200 with no change.
-   Any other invalid transition returns **409**.
-   Every transition writes a `recommendation_events` row in the same
    database transaction as the state change.
-   The endpoint locks the recommendation row, checks ownership and
    current state, applies the transition, and inserts the event
    atomically.

The restore rule guarantees one open recommendation per topic: if a
dismissed recommendation's signature equals the desired signature, no
other recommendation for that signature was created (§10.7 step 4).

### 10.9 Never again (T3)

-   Moves the recommendation to Dismissed and records `dismissed_at`.
-   Ordinary new evidence that leaves the signature unchanged never
    resurrects it.
-   A new recommendation appears only when the status or reason codes
    change.
-   The learner can view Dismissed recommendations and restore them
    (§10.8).
-   Dismissal never affects performance, status or confidence.

### 10.10 Remind me later

-   The learner chooses a date and time.
-   Must be in the future and no more than 60 days ahead (422
    otherwise).
-   React sends ISO 8601 with a timezone offset; stored as UTC;
    displayed in the learner's local time.
-   No scheduler in v1: due reminders are processed by reconciliation
    (§10.7 step 1).
-   Push and email reminders are future work.

### 10.11 Ordering

Open recommendations are sorted by: 1. Priority 2. `help_requested`
present 3. `mastery_goal` present 4. Lower performance first
(`not_enough_evidence` last) 5. Older evidence first

### 10.12 Recommendations page

-   Dedicated, scrollable, paginated page.
-   Sections: Active, Accepted, Postponed, Dismissed; plus a History
    view (Completed, Obsolete, Superseded).
-   Every active recommendation offers **Accept**, **Remind me later**,
    **Never again**.
-   The dashboard may show a shortlist (top 5 active); the full page is
    authoritative.

### 10.13 Revise topic

Every active topic offers an optional **Revise topic** action regardless
of status. It creates a revision quiz (§6.7). Requesting revision is not
evidence of poor performance.

------------------------------------------------------------------------

## 11. Database structure

Proposed logical structure; Role 1 approves all shared changes. All
tables have Laravel timestamps unless stated. State and type columns are
`varchar` cast to PHP backed enums (not MySQL `ENUM`).

### 11.1 Content

**topics** --- `id`, `course_id`, `name`, `description`,
`learning_outcomes` (JSON/TEXT), `status`

**questions** --- `id`, `topic_id`, `question_type`, `question_text`,
`difficulty`, `max_marks` DECIMAL(6,2), `answer_data` (JSON: options
with stable IDs, correct option IDs), `marking_reference`,
`explanation`, `status`

**assessments** --- `id`, `course_id`, `assessment_type` (`quiz`,
`revision`), `owner_learner_id` (nullable), `topic_id` (nullable;
revision only), `title`, `status` (`draft`, `open`, `closed`),
`opens_at`, `closes_at` (nullable), `closed_at`, `closed_by`

**assessment_items** --- `id`, `assessment_id`, `question_id`,
`position` Unique `(assessment_id, question_id)`. No max-marks override.

### 11.2 Attempts and answers

**attempts** --- `id`, `learner_id`, `assessment_id`, `attempt_number`,
`status`, `started_at`, `submitted_at`, `finalised_at`, `finalised_by`
(`learner`, `system`, `lecturer`), `voided_by`, `voided_reason`,
`active_key` (generated, §11.6) Unique
`(learner_id, assessment_id, attempt_number)`. Index
`(learner_id, status)`.

**attempt_items** --- `id`, `attempt_id`, `question_id`, `position`,
`question_type_snapshot`, `question_text_snapshot`, `topic_id_snapshot`,
`difficulty_snapshot`, `max_marks_snapshot`, `option_order` (JSON),
`options_snapshot` (JSON containing stable option IDs and
learner-visible text), `marking_snapshot` (protected JSON or immutable
question-version reference), `question_version_reference` Unique
`(attempt_id, question_id)`. Learner-facing serializers must exclude
`marking_snapshot` and any correct-answer data.

**answers** --- `id`, `attempt_id`, `question_id`, `response` (JSON:
selected option IDs or text; null if blank), `answered_at` Unique
`(attempt_id, question_id)`.

### 11.3 Mark versions

**mark_versions** --- `id`, `answer_id`, `version`, `status` (`auto`,
`pending`, `approved`, `rejected`), `reason` (`initial`, `remark`,
`regrade`), `earned_marks`, `max_marks`, `topic_id`, `difficulty`,
`source_type`, `evidence_key`, `finalised_at`, `marked_by`, `marked_at`,
`approved_by`, `approved_at`, `rejection_reason`, `created_at` Unique
`(answer_id, version)`. Indexes `(topic_id, evidence_key)`,
`(answer_id, status)`.

### 11.4 Assignment evidence

**assignment_results** --- `id`, `course_id`, `learner_id`,
`assignment_reference`, `submission_reference`, `result_version`,
`submitted_at`, `approved_by_reference`, `approved_at`,
`idempotency_key`, `payload_hash`, `received_at` Unique
`(submission_reference, result_version)`. Unique `(idempotency_key)`.

**assignment_criteria** --- `id`, `assignment_result_id`,
`criterion_reference`, `topic_id`, `difficulty`, `earned_marks`,
`max_marks`, `evidence_key` Unique
`(assignment_result_id, criterion_reference)`.

### 11.5 Goals and recommendations

**learner_topic_goals** --- `id`, `learner_id`, `topic_id`, `goal_type`
(`mastery`, `help`), `removed_at`, `active_key` (generated)

**recommendations** --- fields in §10.5 plus `open_topic_key`
(generated, nullable; §11.6). Index
`(learner_id, topic_id, created_at)`, `(learner_id, state)`.

**recommendation_events** --- `id`, `recommendation_id`, `learner_id`,
`from_state`, `to_state`, `actor` (`learner`, `system`), `reminder_at`,
`created_at`

### 11.6 One-active-row constraints

MySQL has no partial unique indexes. Use stored generated columns
(unique indexes allow multiple NULLs):

``` sql
alter table attempts add column active_key varchar(64) as (case when status = 'in_progress' then concat(learner_id, ':', assessment_id) end) stored, add unique key attempts_active_key_unique (active_key);
```

``` sql
alter table learner_topic_goals add column active_key varchar(96) as (case when removed_at is null then concat(learner_id, ':', topic_id, ':', goal_type) end) stored, add unique key goals_active_key_unique (active_key);
```

Laravel equivalent:
`$table->string('active_key', 64)->nullable()->storedAs("...")->unique();`

The current mark version needs no constraint: it is the highest version
with status `auto` or `approved` (§4.5).

``` sql
alter table recommendations
  add column open_topic_key varchar(96)
    as (
      case
        when state in ('active', 'accepted', 'postponed')
        then concat(learner_id, ':', topic_id)
        else null
      end
    ) stored,
  add unique key recommendations_one_open_per_topic (open_topic_key);
```

The unique key is a database-level backstop for the rule that a learner
may have at most one open recommendation per topic. Closed states
(`dismissed`, `completed`, `obsolete`, `superseded`) produce `NULL`, so
historical rows remain unrestricted.

**Recommendation serialization and concurrency:** - Reconciliation for
one learner runs inside a database transaction and first locks that
learner's row with `lockForUpdate()`. This serializes reconciliation for
that learner and prevents two concurrent requests from independently
creating open recommendations. - State-transition endpoints lock the
target recommendation row with `lockForUpdate()` and verify the current
state before changing it. - The recommendation row update and its
corresponding `recommendation_events` insert occur in the same
transaction. - The generated unique key remains the final integrity
guard. If a uniqueness conflict still occurs, roll back and retry
reconciliation from fresh database state; do not return two open rows or
silently discard an event. - All code paths that create or transition
recommendations must use the same transaction and locking rules.

**\[D9\]** Confirm MySQL 8 or MariaDB 10.x before writing migrations.
Verify generated-column syntax, JSON behaviour and index limits against
the actual server version. Do not assume compatibility based only on the
local development stack.

### 11.7 Stored vs computed

  ---------------------------------------------------------------------
  Stored                             Computed at read time
  ---------------------------------- ----------------------------------
  Topics, questions, assessments,    Current evidence set
  attempts, attempt items, answers,  
  mark versions                      

  Assignment results and criteria    Performance, status, coverage

  Goals                              Difficulty class, confidence,
                                     conflict

  Recommendations and events         Evidence age, due reminders
  ---------------------------------------------------------------------

Any cache must exclude time-dependent values and be invalidated on
evidence changes.

------------------------------------------------------------------------

## 12. SmartMark integration

### 12.1 Endpoint and authentication

`POST /api/integrations/smartmark/assignment-results` (final route
agreed with Roles 1 and 5).

-   Service token scoped to this endpoint only, **or** HMAC-SHA256
    signature.
-   With HMAC: headers `X-LearnLoop-Timestamp` and
    `X-LearnLoop-Signature` = HMAC of `timestamp + "." + raw body`.
    Requests more than 5 minutes old are rejected.
-   Header `Idempotency-Key` required.

### 12.2 Payload

``` json
{
  "assignment_reference": "WT-A3",
  "submission_reference": "SM-2041",
  "result_version": 2,
  "course_id": 7,
  "learner_id": 118,
  "submitted_at": "2026-11-10T14:00:00Z",
  "approval_status": "approved",
  "approved_by": "lecturer-22",
  "approved_at": "2026-11-15T09:30:00Z",
  "criteria": [
    { "criterion_reference": "C1", "topic_id": 12, "difficulty": "medium", "earned_marks": 7, "max_marks": 10 },
    { "criterion_reference": "C2", "topic_id": 13, "difficulty": "hard", "earned_marks": 3, "max_marks": 10 }
  ]
}
```

### 12.3 Processing rules

-   The whole payload is processed in one transaction; partial saves
    never occur.
-   Only `approval_status = approved` is accepted.
-   Each criterion must have exactly one `topic_id` belonging to
    `course_id`; the learner must be enrolled in that course.
-   Missing difficulty defaults to **\[D10\]** `medium`.
-   A new `result_version` **fully replaces** the criteria of that
    submission.
-   The current result of a submission is its highest `result_version`,
    regardless of arrival order.
-   A resubmission (new `submission_reference`) shares criterion
    evidence keys and is ordered by `submitted_at`.
-   After saving, the learner's recommendations are reconciled.

### 12.4 Idempotency and responses

  --------------------------------------------------------------------------
  Situation                                  Response
  ------------------------------------------ -------------------------------
  New result stored                          201

  Same `Idempotency-Key`, same payload hash  200, no change

  Same `Idempotency-Key`, different payload  409
  hash                                       

  Same                                       409
  `(submission_reference, result_version)`   
  with a different key                       

  Lower `result_version` than one already    201 stored for history; does
  stored                                     not become current

  Invalid payload, unknown topic, wrong      422
  course, not enrolled, not approved         

  Authentication failure                     401
  --------------------------------------------------------------------------

**\[D11\]** Confirm with Role 5: payload fields, learner ID mapping
(SmartMark learner IDs ↔ LearnLoop user IDs), default difficulty.

------------------------------------------------------------------------

## 13. API

All routes follow Role 1's authentication conventions. Learner routes
are scoped to the authenticated learner. Marks and percentages are
returned as **JSON numbers**, not strings. Lists are paginated (Laravel
default `data`, `links`, `meta`).

### 13.1 Learner routes

**Topics and performance** - `GET /api/me/topics?course_id=` --- topics
with summary performance; includes `show_diagnostic_prompt` -
`GET /api/me/topics/{topicId}` -
`GET /api/me/topics/{topicId}/performance` -
`GET /api/me/topics/{topicId}/evidence`

**Assessments and attempts** - `GET /api/me/assessments?course_id=` ---
excludes other learners' revision quizzes -
`GET /api/me/assessments/{assessmentId}` -
`POST /api/me/assessments/{assessmentId}/attempts` --- 409 if an attempt
is in progress or the assessment is closed -
`GET /api/me/attempts/{attemptId}` --- frozen items in stored order; no
answer keys - `PUT /api/me/attempts/{attemptId}/answers/{questionId}`
--- upsert; 409 if not `in_progress` or closed -
`POST /api/me/attempts/{attemptId}/submit` --- body
`{ "confirm_unanswered": true }` when needed

**Revision** - `POST /api/me/topics/{topicId}/revision-quiz` --- creates
the quiz and starts an attempt; 422 if fewer than 3 eligible questions

**Goals** - `GET /api/me/goals` -
`POST /api/me/topics/{topicId}/goals` -
`DELETE /api/me/topics/{topicId}/goals/{goalId}` -
`POST /api/me/topics/{topicId}/help-requests` -
`DELETE /api/me/topics/{topicId}/help-requests/{requestId}`

**Recommendations** -
`GET /api/me/recommendations?state=active|accepted|postponed|dismissed|history` -
`GET /api/me/recommendations/shortlist` -
`POST /api/me/recommendations/{id}/accept` -
`POST /api/me/recommendations/{id}/postpone` --- body
`{ "reminder_at": "2026-11-27T09:00:00+00:00" }` -
`POST /api/me/recommendations/{id}/dismiss` -
`POST /api/me/recommendations/{id}/restore`

### 13.2 Lecturer routes (course-scoped)

**Topics** - `GET /api/courses/{courseId}/topics` --- includes topic
size and warnings - `POST /api/courses/{courseId}/topics` -
`PATCH /api/topics/{topicId}` - `POST /api/topics/{topicId}/archive`

**Questions** - `GET /api/topics/{topicId}/questions` --- includes
answer keys - `POST /api/topics/{topicId}/questions` -
`PATCH /api/questions/{questionId}` --- enforces §3.2 edit rules (422 on
forbidden edits) - `POST /api/questions/{questionId}/archive` -
`POST /api/questions/{questionId}/regrade`

**Assessments** - `GET /api/courses/{courseId}/assessments` -
`POST /api/courses/{courseId}/assessments` -
`PATCH /api/assessments/{assessmentId}` -
`PUT /api/assessments/{assessmentId}/items` -
`POST /api/assessments/{assessmentId}/open` -
`POST /api/assessments/{assessmentId}/close`

**Attempts and marking** -
`GET /api/assessments/{assessmentId}/attempts` -
`POST /api/attempts/{attemptId}/void` --- body `{ "reason": "..." }` -
`GET /api/courses/{courseId}/marking-queue` -
`POST /api/answers/{answerId}/marks` --- body
`{ "earned_marks": 3.5 }` -
`POST /api/mark-versions/{markVersionId}/approve` -
`POST /api/mark-versions/{markVersionId}/reject` --- body
`{ "reason": "..." }`

**Learner summaries** -
`GET /api/courses/{courseId}/learners/{learnerId}/topic-performance`

### 13.3 Status codes

  ---------------------------------------------------------------------
  Code                               Use
  ---------------------------------- ----------------------------------
  200                                Success; also repeated idempotent
                                     actions

  201                                Created

  401                                Not authenticated

  403                                Authenticated but not permitted
                                     (wrong learner, wrong course)

  404                                Not found, or hidden for privacy

  409                                Invalid state transition, attempt
                                     not in progress, assessment
                                     closed, idempotency mismatch

  422                                Validation failure, including
                                     unconfirmed blank answers
  ---------------------------------------------------------------------

### 13.4 Performance response (contract for React)

``` json
{
  "topic_id": 12,
  "status": "strong_unconfirmed",
  "label": "Strong so far — more evidence needed",
  "earned_marks": 8,
  "max_marks": 9,
  "performance_display": 88.8,
  "coverage_band": "low",
  "difficulty_class": "mixed",
  "confidence": "limited",
  "confidence_reasons": [{ "code": "low_coverage", "text": "Fewer than 10 marks of evidence so far." }],
  "evidence_age": "recent",
  "last_assessed_at": "2026-11-18T10:00:00Z",
  "pending_count": 1,
  "conflict": {
    "pairs": [
      {
        "source_a": "auto_quiz",
        "source_b": "lecturer_quiz",
        "earned_a": 9,
        "max_a": 10,
        "percentage_a": 90.0,
        "earned_b": 2,
        "max_b": 5,
        "percentage_b": 40.0,
        "difference_percentage_points": 50.0,
        "is_conflict": true,
        "both_meet_minimum_evidence": true,
        "reduces_confidence": true
      }
    ],
    "confidence_reduced": true
  },
  "explanation": "You have scored 8/9 so far. More assessment evidence is needed before mastery can be confirmed."
}
```

For `not_enough_evidence`, `earned_marks`, `max_marks`,
`performance_display`, `difficulty_class` and `confidence` are `null`.

------------------------------------------------------------------------

## 14. Security and authorisation

-   Learner routes use the authenticated user; route model binding is
    scoped so a learner can only reach their own attempts, answers,
    goals and recommendations (403 or 404 otherwise).
-   Lecturer routes use Laravel policies based on **course-staff**
    membership supplied by Role 1. A lecturer of one course cannot view
    or act on another course's topics, attempts, marking queue or
    learners.
-   Learner endpoints never expose answer keys, marking references or
    other learners' data outside the reveal policy (§6.8).
-   SmartMark uses service authentication only (§12.1).
-   Marking, approval, rejection, regrade, voiding and closure are
    recorded in Role 1's audit log.

**Enrolment changes:** if a learner leaves a course, their evidence is
retained, their open recommendations for that course become `obsolete`,
and they lose access to the course's learner routes.

------------------------------------------------------------------------

## 15. React interface

Components receive data through props; fetching lives in hooks or API
services.

### 15.1 Quiz

-   Displays frozen items in stored order with stored option order.
-   Autosaves answers (debounced `PUT`).
-   Shows progress and navigation where permitted.
-   `SubmitAttemptDialog`: on 422 with `requires_confirmation`, shows
    "You have N unanswered questions. Submitting will count them as zero
    and replace your previous results for them." Confirm resubmits with
    `confirm_unanswered: true`.
-   Handles 409 (closed or already submitted) with a clear message.

### 15.2 Results and topic cards

Show: topic, earned/max, displayed percentage, status label, coverage
band, confidence with reasons, evidence-age classification and **Last
assessed** date, pending-marking indicator, conflict notice identifying
each disagreeing source pair and its evidence amounts, explanation,
recommended next action, Revise topic. Never present coverage as a
fraction of topic size.

### 15.3 Recommendations

Dedicated page with Active, Accepted, Postponed, Dismissed and History;
reasons, priority, actions; reminder date-time picker (future, ≤ 60
days); restore with an explanation when it no longer applies.

### 15.4 Lecturer

Topic management with size and warnings; question bank with tagging,
edit-rule messages and regrade; assessment management with open/close;
marking queue with mark, approve and reject; attempt voiding; learner
performance summaries.

### 15.5 Proposed components

`TopicPerformanceCard`, `TopicProgressOverview`,
`TopicConfidenceIndicator`, `EvidenceCoverageIndicator`,
`EvidenceAgeLabel`, `PendingMarksIndicator`, `ConflictNotice`,
`RevisionButton`, `QuizPlayer`, `SubmitAttemptDialog`,
`RecommendationCard`, `RecommendationList`,
`RecommendationReminderDialog`, `RecommendationHistory`,
`DiagnosticPrompt`, `LearningGoalsManager`, `AttemptHistory`,
`TopicEvidenceSummary`, `LecturerTopicManager`, `QuestionBankManager`,
`AssessmentManager`, `MarkingQueue`.

------------------------------------------------------------------------

## 16. Testing requirements

All expected results are exact. Thresholds marked provisional are tested
with their current values and updated if changed.

### 16.1 Performance and coverage

1.  Zero evidence → `not_enough_evidence`, no division, percentage null.
2.  Zero evidence with one pending answer → label "Awaiting marking",
    `pending_count` 1.
3.  4/10 → `needs_help`.
4.  5/10 → `getting_there`.
5.  7.99/10 → `getting_there`; displays 79.9%.
6.  8/10 → `strong`.
7.  8/9 → `strong_unconfirmed`; displays 88.8%.
8.  9 marks of coverage does not satisfy the 10-mark requirement;
    exactly 10 does.
9.  19.99/25 → displays 79.9%, status `getting_there`.
10. Exactly 20 marks → Broad; 19 → Medium; 9 → Low.
11. Assignment evidence makes coverage exceed topic size; no fraction is
    displayed.
12. Adding questions to the bank does not change any learner's status.
13. Each evidence key counts once, however many attempts exist.

### 16.2 Attempts, closure and answers

14. In-progress attempt does not count.
15. Submitted blank objective item counts as 0.
16. Blank open-ended item counts as 0 without approval.
17. Submit with blanks and no confirmation → 422 with count; with
    confirmation → submitted.
18. Double submit → second returns 409; one finalisation only.
19. Lazy auto-close sets `finalised_at` to the closure time; a later
    revision answer to the same question stays current.
20. Manual close uses `closed_at`; `closes_at` expiry uses `closes_at`.
21. Answer save after closure → 409. Attempt start after closure → 409.
22. Second in-progress attempt on the same assessment → 409 (and blocked
    by the unique index).
23. Duplicate autosave → one answer row.
24. Shuffled options are marked correctly by option ID.
25. Page refresh returns the same question and option order.
26. Question added to an assessment mid-attempt is not part of that
    attempt and does not count as 0.
27. Two attempts with the same `finalised_at` → higher attempt ID wins.

### 16.3 Versions, regrade and retakes

28. New objective attempt replaces the previous contribution for that
    key.
29. Pending mark in a newer attempt → previous approved result still
    counts.
30. Rejected mark → previous eligible result still counts; answer
    returns to queue.
31. Approved re-mark replaces the previous version; old version
    retained.
32. Re-marking an older attempt does not override a newer attempt.
33. Voiding the latest attempt restores the previous result.
34. Concurrent marking produces unique version numbers.
35. Changing `max_marks` on an answered question → 422.
36. Changing a question's topic or difficulty does not change existing
    evidence.
37. Regrade creates new `auto` versions only for changed marks; history
    retained; recommendations reconciled.
38. Inactive question's past evidence still counts; excluded from topic
    size.

### 16.4 Revision quizzes

39. Revision quiz is visible only to its owner.
40. Fewer than 3 eligible questions → 422.
41. Prefers questions not answered in the last 7 days.
42. Unfinished revision attempt never counts and is never auto-closed.
43. Revision results show correct/incorrect only, never the answer key.

### 16.5 Difficulty and confidence

44. Coverage \< 10 → Limited.
45. Medium + Basic → Moderate; Medium + Mixed → Moderate; Medium +
    Challenging → High.
46. Broad + Basic → Moderate; Broad + Mixed → High; Broad + Challenging
    → High.
47. Easy-only success with 20+ marks → Moderate, never High.
48. Positive status: Medium+Hard earned 24% → Basic; 50% with Hard 15% →
    Challenging; 60% with Hard 10% → Mixed.
49. Needs help: Easy+Medium lost 20% → Basic; 30% → Mixed; 60% →
    Challenging.
50. Needs help with Medium coverage and Challenging difficulty → High
    confidence.
51. `strong_unconfirmed` always has Limited confidence.
52. Difficulty class exists whenever evidence exists.
53. Outdated evidence does not change performance, status or confidence.
54. Evidence age computed at read time changes from Recent to Older as
    days pass.

### 16.6 Conflicts

55. Difference 29.9 points → no conflict.
56. Exactly 30 points → conflict.
57. Both groups ≥ 5 marks → confidence reduced one level.
58. One group \< 5 marks → conflict flagged, confidence not reduced,
    `conflict_limited` recommendation.
59. Reduction never exceeds one level.
60. The §8.3 example compares all three source pairs independently: only
    `auto_quiz` vs `lecturer_quiz` conflicts.
61. A conflicting pair with either group below 5 maximum marks is
    flagged and recommended, but does not reduce confidence under T1.
62. If one pair qualifies for confidence reduction and another
    small-source pair also conflicts, overall confidence is reduced only
    once.
63. Combined performance stays mark-weighted across all eligible
    evidence; source groups are not averaged equally.
64. Conflict details identify every compared pair, each pair's evidence
    amounts, difference and T1 minimum-evidence result in the
    performance response and recommendation `basis`.

### 16.7 Recommendations

63. Every row of the §10.3 table produces the stated priority (one test
    per cell).
64. Strong, no goal, no help, no conflict, recent evidence → no
    recommendation (D3).
65. Strong with outdated evidence → Optional `evidence_outdated`.
66. Not assessed with no goal → no recommendation;
    `show_diagnostic_prompt` true.
67. Needs help + conflict → one recommendation with both reasons,
    priority High.
68. Ordering follows §10.11.
69. Accept → `accepted`; repeated accept → 200, no change.
70. Postpone with past time → 422; \> 60 days → 422; valid →
    `postponed`.
71. Due postponed recommendation returns to `active` if still desired,
    else `obsolete`.
72. Due reminder is processed when only the dashboard shortlist is
    loaded.
73. Never again → `dismissed`; new evidence with the same signature does
    not resurrect it.
74. Status change after dismissal creates a new recommendation.
75. Flip-back (Needs help dismissed → Getting there → Needs help)
    creates a new Needs help recommendation (D4).
76. Restore when signature still desired → `active`; when not → stays
    dismissed with explanation.
77. Restore never produces two open recommendations for one topic.
78. Accept, then a qualifying attempt that also changes status →
    `completed` (not `superseded`).
79. Accepted recommendation becomes `obsolete` when approved assignment
    evidence removes the need.
80. Superseded postponed recommendation carries its future `reminder_at`
    to the new one.
81. Accepting a dismissed recommendation → 409.
82. Every transition writes a `recommendation_events` row.
83. Completed recommendation is not treated as mastery.
84. Archived topic → open recommendations become `obsolete`.

### 16.8 SmartMark

85. New result → 201 and evidence created.
86. Same key and payload → 200, no duplicate.
87. Same key, different payload → 409.
88. Version 2 replaces version 1 without double counting.
89. Version 3 arriving before version 2 → version 3 remains current.
90. Resubmission shares criterion keys; latest `submitted_at` wins.
91. Topic outside the course, learner not enrolled, or not approved →
    422. 
92. Invalid signature, expired timestamp or wrong token → 401.
93. Missing difficulty → stored as the default (D10).
94. Partial failure stores nothing.

### 16.9 Security

95. Learner cannot read or modify another learner's attempt, answer,
    evidence, goal or recommendation.
96. Learner endpoints never return answer keys or marking references
    outside the reveal policy.
97. Lecturer of course A gets 403 on course B's topics, marking queue,
    approvals and learner summaries.
98. Learner gets 403 on all lecturer routes.
99. Learner who left a course loses access; their open recommendations
    become `obsolete`.
100. Attempt-time question text, topic, difficulty, max marks, options
     and option order remain unchanged if the question bank is edited
     after the attempt starts.
101. Finalised evidence uses the attempt-time topic, difficulty and
     max-marks snapshots, not the current question row.
102. A learner cannot receive two open recommendations for the same
     topic under concurrent reconciliation requests; the generated
     unique key rejects duplicates.
103. Concurrent accept/dismiss/postpone requests serialize through row
     locking; only a valid transition and its matching event are
     committed.
104. A recommendation state change without its `recommendation_events`
     row cannot commit.
105. The same lecturer can mark and then separately approve; both actor
     IDs and timestamps are recorded, and marking alone never approves.
106. Missing SmartMark criterion difficulty is stored as `medium`.
107. Percentage display always rounds down to one decimal place
     (including values just below a threshold).

------------------------------------------------------------------------

## 17. Decision register

The following decisions are confirmed and must be treated as
implementation requirements. Remaining items are provisional or depend
on coordination with other roles.

  -----------------------------------------------------------------------
  ID                      Decision                Default in this spec
  ----------------------- ----------------------- -----------------------
  D1                      Conflict source groups  **Confirmed:** three
                                                  groups compared
                                                  pairwise (`auto_quiz`,
                                                  `lecturer_quiz`,
                                                  `assignment`) (§8.3)

  D2                      Needs help difficulty   Provisional lost-marks
                          rule                    rule and thresholds
                                                  (§7.3)

  D3                      Strong with no          Provisional default: no
                          goal/help/conflict and  recommendation (§10.3)
                          recent evidence         

  D4                      Flip-back to a          Provisional default:
                          previously dismissed    material change creates
                          status                  a new recommendation
                                                  (§10.7)

  D5                      Same lecturer marks and **Confirmed:** allowed;
                          approves                marking and approval
                                                  are separate explicit
                                                  actions with separate
                                                  actor/timestamp records
                                                  (§4.5)

  D6                      Answer reveal policy    Current proposed
                                                  default; confirm with
                                                  teaching team (§6.8)

  D7                      Revision quiz question  Current proposed
                          types                   default: objective only
                                                  (§6.7)

  D8                      Auto-close mechanism    Current proposed
                                                  default: lazy only in
                                                  v1 (§6.4)

  D9                      Database engine and     Must confirm against
                          version                 the actual development
                                                  environment (§11.6)

  D10                     Default assignment      **Confirmed:** `medium`
                          difficulty              when omitted (§12.3)

  D11                     SmartMark payload and   §12.2, to confirm with
                          learner ID mapping      Role 5

  D12                     Course-staff and        To be provided by Role
                          enrolment data          1 (§14)
  -----------------------------------------------------------------------

### 17.1 T1 implementation note

T1 remains unchanged. A conflict involving a very small source is still
flagged and triggers additional practice or assessment. Confidence is
reduced only when both groups in that particular conflicting pair have
at least 5 maximum marks. With three independent source groups, a
learner may see an early conflict after a small amount of open-ended or
assignment evidence. The interface should make the evidence size visible
so the learner can interpret the signal appropriately. Do not suppress
small-source conflicts or alter T1 to reduce noise.

### 17.2 Provisional values to tune with sample data

-   Difficulty thresholds: 25%, 50%, 15% (§7.2, §7.3)
-   Revision quiz size (10) and minimum (3); "recently answered" window
    (7 days) (§6.7)
-   Reminder maximum (60 days) (§10.10)
-   Dashboard shortlist size (5) (§10.12)

------------------------------------------------------------------------

## 18. Future work

-   Cooldowns or rate limits on revision quizzes
-   Push and email reminders (requires scheduler and queue workers)
-   SmartMark "withdrawn result" messages
-   Multi-topic questions
-   Assessment-specific evidence weighting
-   Lecturer and class-level analytics
-   Performance history snapshots for charts
-   Per-learner deadline extensions
-   Open-ended questions in revision quizzes

------------------------------------------------------------------------

## 19. Implementation principles

1.  Performance, coverage and confidence are separate measurements.
2.  Topic size is separate from coverage and never its denominator.
3.  All current eligible marks contribute to mark-weighted performance.
4.  Each evidence key contributes once; history is never lost.
5.  Evidence snapshots are immutable; marks change only through new
    versions.
6.  Pending or rejected marks never replace approved marks.
7.  Ordering uses finalisation time, never processing or marking time.
8.  Difficulty affects confidence, not performance; recency affects
    neither.
9.  Goals and help requests are preferences, not evidence.
10. Conflicts are always visible; confidence is reduced only with
    sufficient evidence.
11. Every recommendation explains itself; learners control accept,
    postpone and dismiss.
12. Dismissed recommendations are not resurrected by ordinary new
    evidence.
13. Strong mastery requires at least 10 marks of current valid evidence.
14. All calculation rules live in one service layer and are covered by
    automated tests.
15. Security, auditability and correct evidence handling take priority
    over convenience.

------------------------------------------------------------------------

## 20. Implementation approach

Implementation will proceed incrementally. The specification is the
working baseline, not a reason to delay all coding until every
provisional value is settled. When an issue appears during
implementation, record it, assess whether it changes a confirmed rule or
shared contract, and update the specification and tests before changing
behaviour.

### 20.1 Recommended build order

1.  **Repository and environment inspection**
    -   Confirm the existing Laravel and React structure, PHP/Laravel
        versions, database engine/version, authentication conventions
        and Role 1 integration points.
    -   Do not replace existing project conventions without checking
        them first.
2.  **Database foundation**
    -   Add migrations for topics, questions, assessments, assessment
        items, attempts, attempt items, answers and mark versions.
    -   Include attempt-time snapshots and the required unique
        constraints.
    -   Add factories and migration tests.
3.  **Calculation core**
    -   Implement current evidence assembly, mark-weighted performance,
        coverage, status, round-down display, difficulty, confidence and
        pairwise conflict detection as testable service classes.
    -   Keep calculations independent from controllers and React.
4.  **Question bank and assessment management**
    -   Implement lecturer-scoped topic/question CRUD, validation and
        assessment setup.
5.  **Attempt lifecycle and marking**
    -   Implement attempt creation, frozen items, autosave, submission,
        lazy closure, automatic marking, lecturer marking, separate
        approval/rejection, voiding and regrade.
6.  **Recommendations**
    -   Implement goals/help requests, priority calculation,
        transactional reconciliation, unique open-topic enforcement,
        state transitions and event history.
7.  **SmartMark integration**
    -   Implement the agreed contract after Role 5 confirms learner ID
        mapping and payload details.
8.  **API and React integration**
    -   Deliver learner quiz/results/recommendation pages and lecturer
        management/marking interfaces.
9.  **Hardening**
    -   Add concurrency, authorisation, integration and regression
        tests; profile queries and remove N+1 access patterns.

### 20.2 Working rules

-   Build one vertical slice at a time and keep it runnable.
-   Write tests alongside each business rule, especially evidence
    selection, mark versioning, conflict pairs and recommendation
    transitions.
-   Never change T1--T4 or the four confirmed v0.7 decisions as an
    incidental implementation shortcut.
-   If a provisional rule proves unsuitable, demonstrate the issue with
    test cases or sample data, agree on the revised rule, then update
    this specification and tests together.
-   Coordinate migrations and shared contracts with Role 1; coordinate
    assignment payload and learner mapping with Role 5.
-   Do not implement assumptions about authentication, enrolment,
    course-staff membership or SmartMark identifiers before confirming
    the shared contract.

------------------------------------------------------------------------

**End of Specification**

**Version:** 0.7 **Review status:** Confirmed decisions D1, D5 and D10
integrated; C13 retained; T1--T4 preserved. Technical safeguards for
attempt snapshots and recommendation concurrency added. **Implementation
status:** Ready for incremental implementation. Provisional product
rules and cross-role contracts remain tracked in §17.
