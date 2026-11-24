<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learner goals / help requests and recommendations with full history (spec §9, §10, §11.5–§11.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_topic_goals', function (Blueprint $table) {
            $table->id();
            // MySQL forbids CASCADE on columns used by a stored generated column (active_key below), so these
            // foreign keys restrict deletes; services remove dependent rows explicitly where needed.
            $table->foreignId('learner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('goal_type', 20);              // mastery | help
            $table->timestamp('removed_at')->nullable();  // soft delete keeps history
            $table->timestamps();

            // One active row per learner, topic and goal type (§11.6).
            $table->string('active_key', 96)->nullable()->storedAs(
                $this->caseConcat('removed_at is null', ['learner_id', 'topic_id', 'goal_type'])
            )->unique();
            $table->index(['learner_id', 'removed_at']);
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            // MySQL forbids CASCADE on columns used by a stored generated column (open_key below), so these
            // foreign keys restrict deletes; services remove dependent rows explicitly where needed.
            $table->foreignId('learner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('signature', 191);              // topic|status|sorted reason codes (§10.6)
            $table->string('status_at_creation', 30);
            $table->string('primary_reason', 30);
            $table->json('reason_codes');
            $table->string('priority', 10);                // high | medium | low | optional
            $table->json('basis');                         // values that explained it at creation (§10.5)
            $table->string('state', 20);                   // active | accepted | postponed | dismissed | completed | obsolete | superseded
            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('obsolete_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('recommendations')->nullOnDelete();
            $table->timestamps();

            // At most one OPEN recommendation per learner and topic (C20, §11.6).
            $table->string('open_key', 64)->nullable()->storedAs(
                $this->caseConcat("state in ('active', 'accepted', 'postponed')", ['learner_id', 'topic_id'])
            )->unique();
            $table->index(['learner_id', 'topic_id', 'created_at']);
            $table->index(['learner_id', 'state']);
        });

        Schema::create('recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_state', 20)->nullable();  // null = created
            $table->string('to_state', 20);
            $table->string('actor', 10);                   // learner | system
            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['recommendation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('learner_topic_goals');
    }

    /** "case when <condition> then a:b[:c] end" in the current driver's syntax (see attempts migration). */
    private function caseConcat(string $condition, array $columns): string
    {
        $joined = DB::connection()->getDriverName() === 'sqlite'
            ? implode(" || ':' || ", $columns)
            : 'concat('.implode(", ':', ", $columns).')';

        return "case when {$condition} then {$joined} end";
    }
};
