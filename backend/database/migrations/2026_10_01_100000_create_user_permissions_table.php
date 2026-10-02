<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the per-user permission override table.
     *
     * A row records a user-specific deviation from what the user's roles grant
     * (`effect` = `allow` or `deny`). The table is deliberately **not** read by
     * the authorization resolver yet: it only persists overrides so a later
     * phase can apply the `user deny > user allow > role permission > no access`
     * precedence. Until then no rows exist, so authorization behaves exactly as
     * before.
     *
     * Tenancy: `permissions` is a global catalogue with no `business_id`, but a
     * user override is tenant-owned data. Every row therefore carries the
     * `business_id` of the user it belongs to and cascades with that business,
     * so an override can never outlive its tenant. MySQL cannot express "this
     * user must belong to this business" as a composite foreign key here, so
     * keeping `user_permissions.business_id` equal to `users.business_id` is
     * enforced by the application layer when overrides are written (the next
     * phase); this migration only guarantees the referential integrity it can.
     *
     * The two-state `effect` is stored as a short string — the same approach the
     * existing schema uses for categorical columns such as
     * `stock_movements.type` — with the allowed `allow`/`deny` values declared on
     * the `UserPermission` model. No DB-level enum/check is used, matching the
     * rest of the schema.
     */
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            // Tenant owner. Cascading means an override is deleted with its
            // business (a hard delete), so it cannot survive independently of
            // the tenant that owns it — consistent with the other
            // tenant-owned child tables (`categories`, `products`, ...).
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 10);
            $table->timestamps();

            // At most one override per user + permission inside a business.
            $table->unique(['business_id', 'user_id', 'permission_id']);
            $table->index('user_id');
            $table->index('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
