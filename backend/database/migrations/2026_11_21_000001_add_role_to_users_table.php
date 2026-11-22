<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `role` column used by CheckRole (student, lecturer, admin).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // string, not ENUM: allowed values (student, lecturer, admin) are
            // enforced in application code (User::ROLES), per AGENTS.md.
            $table->string('role', 20)
                ->default('student')
                ->after('password')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
