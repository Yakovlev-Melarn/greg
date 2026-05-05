<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_adjustment_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_adjustment_id')->constrained('driver_adjustments')->cascadeOnDelete();
            $table->unsignedInteger('part_no');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->boolean('is_applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['driver_adjustment_id', 'due_date']);
            $table->unique(['driver_adjustment_id', 'part_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_adjustment_parts');
    }
};
