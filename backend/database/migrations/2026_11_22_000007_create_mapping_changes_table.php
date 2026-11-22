<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Log of lecturer changes to Canvas topic mappings (v0.8 §3.3, D13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('canvas_assignment_id', 64);
            $table->string('canvas_criterion_id', 64)->default('');
            $table->foreignId('old_topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->foreignId('new_topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->string('old_difficulty', 10)->nullable();
            $table->string('new_difficulty', 10)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['course_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_changes');
    }
};
