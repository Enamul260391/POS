<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit business ownership to roles without fabricating ownership.
     */
    public function up(): void
    {
        if (DB::table('roles')->exists() && DB::table('roles')->count() > 0) {
            $roleCount = DB::table('roles')->count();

            throw new RuntimeException(
                "Role ownership cannot be established safely: {$roleCount} existing role(s) require a business_id backfill.",
            );
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->after('id')
                ->constrained('businesses')
                ->restrictOnDelete();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->index('business_id');
            $table->unique(['business_id', 'slug']);
        });
    }

    /**
     * Reverse only the ownership changes made by this migration.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'slug']);
            $table->dropIndex(['business_id']);
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('slug');
        });
    }
};
