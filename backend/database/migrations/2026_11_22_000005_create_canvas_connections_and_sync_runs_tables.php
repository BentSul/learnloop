<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canvas connections (identity + tokens) and sync history (v0.8 §3.1, §3.6–§3.7, §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canvas_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('canvas_user_id', 64)->unique();
            $table->string('adapter', 20);                 // fixture | token | oauth
            $table->text('access_token')->nullable();      // encrypted cast; never logged or returned
            $table->text('refresh_token')->nullable();     // encrypted cast
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);                  // canvas | klenam
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);                  // succeeded | failed
            $table->json('summary')->nullable();           // counts: created, unchanged, skipped, flagged
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id', 'source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('canvas_connections');
    }
};
