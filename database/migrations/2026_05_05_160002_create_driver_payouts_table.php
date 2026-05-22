<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->date('week_monday');
            $table->decimal('amount', 12, 2);
            $table->decimal('accrual_amount', 12, 2);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->timestamp('paid_at');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'week_monday']);
            $table->index('week_monday');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_payouts');
    }
};
