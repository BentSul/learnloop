<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canvas topic mappings and external (Canvas / Klenam) evidence (v0.8 §3.3–§3.5, §4.2, §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lecturer mapping of Canvas items or rubric criteria to topics (v0.8 §3.3).
        Schema::create('canvas_item_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('canvas_assignment_id', 64);
            // '' = whole item; otherwise the rubric criterion ID. Not nullable so the unique index works.
            $table->string('canvas_criterion_id', 64)->default('');
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('difficulty', 10)->default('medium'); // D10 default
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'canvas_assignment_id', 'canvas_criterion_id'], 'canvas_mappings_item_unique');
        });

        // One row per observed version of a learner's external result (append-only, v0.8 §3.5).
        Schema::create('external_results', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);                     // canvas | klenam
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('external_item_ref', 128);         // e.g. Canvas assignment ID
            $table->string('external_submission_ref', 128)->nullable();
            $table->unsignedInteger('version');
            $table->string('score_hash', 64);                 // new version only when this changes
            $table->boolean('posted')->default(false);        // Canvas: grade posted to the student
            $table->boolean('excused')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['source', 'learner_id', 'external_item_ref', 'version'], 'external_results_version_unique');
            $table->index(['learner_id', 'course_id']);
        });

        // Topic-level evidence derived from an external result through the mappings.
        Schema::create('external_result_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_result_id')->constrained()->cascadeOnDelete();
            $table->string('evidence_key', 191);              // c:{course}:{assignment}[:{criterion}]
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('difficulty', 10)->default('medium');
            $table->decimal('earned_marks', 6, 2);
            $table->decimal('max_marks', 6, 2);
            $table->timestamps();

            $table->index(['topic_id', 'evidence_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_result_items');
        Schema::dropIfExists('external_results');
        Schema::dropIfExists('canvas_item_mappings');
    }
};
