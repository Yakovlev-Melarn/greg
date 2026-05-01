<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->foreignId('expense_category_id')
                ->nullable()
                ->after('fleet_vehicle_id')
                ->constrained('expense_categories')
                ->nullOnDelete();
        });

        $this->backfillExpenseCategoriesFromLegacyCategoryColumn();
    }

    public function down(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
        });
    }

    private function backfillExpenseCategoriesFromLegacyCategoryColumn(): void
    {
        $expenses = DB::table('vehicle_expenses')->select('id', 'category')->get();

        foreach ($expenses as $expense) {
            $trimmed = trim((string) ($expense->category ?? ''));
            if ($trimmed === '') {
                continue;
            }

            $existingId = DB::table('expense_categories')->where('name', $trimmed)->value('id');
            if (! $existingId) {
                $existingId = DB::table('expense_categories')->insertGetId([
                    'name' => $trimmed,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('vehicle_expenses')
                ->where('id', $expense->id)
                ->update(['expense_category_id' => $existingId]);
        }
    }
};
