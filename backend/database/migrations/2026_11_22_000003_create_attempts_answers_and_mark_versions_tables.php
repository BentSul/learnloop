<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attempts with frozen items, answers and versioned marks (spec v0.7 §4.4–§4.5, §6.1, §6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20)->default('in_progress'); // in_progress | submitted | auto_closed | voided
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('voided_reason')->nullable();
            $table->timestamps();

            // At most one in-progress attempt per learner and assessment (§11.6).
            // MariaDB/MySQL have no partial unique index: a stored generated column is NULL
            // for every other status, and NULLs never clash in a unique index.
            $table->string('active_key', 64)->nullable()->storedAs(
                $this->caseConcat("status = 'in_progress'", ['learner_id', 'assessment_id'])
            )->unique();

            $table->unique(['learner_id', 'assessment_id', 'attempt_number']);
            $table->index(['learner_id', 'status']);
        });

        // Frozen at attempt start (§6.5, C19): what the learner saw and the metadata evidence uses.
        Schema::create('attempt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('question_type_snapshot', 20);
            $table->text('question_text_snapshot');
            $table->foreignId('topic_id_snapshot')->constrained('topics')->restrictOnDelete();
            $table->string('difficulty_snapshot', 10);
            $table->decimal('max_marks_snapshot', 6, 2);
            $table->json('options_snapshot')->nullable();  // [{"id":"a","text":"..."}] learner-visible
            $table->json('option_order')->nullable();      // ["c","a","b"] display order
            $table->json('marking_snapshot')->nullable();  // protected: never returned to learners
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->json('response')->nullable(); // {"option_ids":["a"]} or {"text":"..."}; null = blank
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']); // autosave is an upsert (§6.5)
        });

        // Append-only marks with snapshots (§4.4–§4.5).
        Schema::create('mark_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20);                  // auto | pending | approved | rejected
            $table->string('reason', 20)->default('initial'); // initial | remark | regrade
            $table->decimal('earned_marks', 6, 2);
            $table->decimal('max_marks', 6, 2);
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('difficulty', 10);
            $table->string('source_group', 20);            // learnloop (v0.8 D1-R)
            $table->string('evidence_key', 191);           // q:{question_id}
            $table->timestamp('finalised_at');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['answer_id', 'version']);
            $table->index(['topic_id', 'evidence_key']);
            $table->index(['answer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_versions');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('attempt_items');
        Schema::dropIfExists('attempts');
    }

    /**
     * "case when <condition> then <col1>:<col2> end" in the current driver's syntax.
     * MariaDB/MySQL use concat(); SQLite (tests) uses the || operator.
     */
    private function caseConcat(string $condition, array $columns): string
    {
        $joined = DB::connection()->getDriverName() === 'sqlite'
            ? implode(" || ':' || ", $columns)
            : 'concat('.implode(", ':', ", $columns).')';

        return "case when {$condition} then {$joined} end";
    }
};
