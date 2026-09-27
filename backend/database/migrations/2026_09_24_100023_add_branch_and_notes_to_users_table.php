<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two free-text staff fields the `/users` management screen shows.
 *
 * `branch` is a placeholder until real multi-branch selection exists, and
 * `notes` is the short line rendered under the user's name. Both are nullable
 * so an account without them behaves exactly as before this migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('branch', 120)->nullable()->after('phone');
            $table->text('notes')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['branch', 'notes']);
        });
    }
};