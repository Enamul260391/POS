<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the platform identity marker to `users` and enforces the
 * single-Platform-Owner invariant at the database level.
 *
 * ## Column
 *
 * `platform_role` is a nullable string. The only recognised non-NULL value is
 * `owner` (see `User::PLATFORM_ROLE_OWNER`); NULL means "not a platform
 * identity", which is every existing row and every normal business user.
 * `business_id` is deliberately *not* used as the marker — it stays exactly
 * what it was (nullable FK) so the tenant model is untouched.
 *
 * ## Why a plain UNIQUE index guarantees exactly one owner
 *
 * The column is indexed with `unique()`, and both MySQL/InnoDB and SQLite
 * treat every NULL as distinct from every other NULL inside a unique index.
 * Because `owner` is the only non-NULL value this design ever writes, the
 * index translates directly into the invariant:
 *
 *   - zero owners  → every row is NULL            → allowed (bootstrap)
 *   - one owner    → a single 'owner' row          → allowed (production)
 *   - two owners   → duplicate 'owner' value       → rejected by the database
 *   - normal users → platform_role = NULL          → unlimited rows
 *
 * This is the least invasive MySQL-compatible design: one additive column and
 * one index, no table rebuild, no generated/computed column, no
 * PostgreSQL-only partial unique index (which MySQL cannot express), and no
 * reliance on application-level validation alone. It is also enforced
 * identically by the SQLite test database, so the focused tests exercise the
 * same constraint the production database will apply.
 *
 * Creating the index over existing data is safe: every existing row receives
 * NULL, and a unique index accepts any number of NULLs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 30)
                ->nullable()
                ->unique()
                ->after('business_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['platform_role']);
            $table->dropColumn('platform_role');
        });
    }
};
