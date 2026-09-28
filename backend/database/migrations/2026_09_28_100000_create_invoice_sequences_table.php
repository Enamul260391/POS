<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One invoice counter per business.
     *
     * `business_id` is unique, so there is exactly one row to lock when a
     * number is allocated — that uniqueness is the whole concurrency story, see
     * `App\Services\Sale\InvoiceNumberService`. `next_number` is the value the
     * *next* sale of that business will receive; it starts at 1 so the first
     * invoice is `{prefix}-000001`.
     *
     * `unsignedBigInteger` matches the id space of `sales`, and the row is
     * created (not derived) for every existing business so the very first
     * checkout after this migration never has to race to initialise it.
     */
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique('business_id');
        });

        $now = now();
        $rows = DB::table('businesses')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id): array => [
                'business_id' => $id,
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('invoice_sequences')->insert($chunk);
        }
    }

    /**
     * Drop the counter. Sale invoice numbers already written are left alone —
     * they are business records, not part of this table.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
