<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistician_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistician_id')->constrained('logisticians')->cascadeOnDelete();
            $table->date('week_monday');
            $table->decimal('route_sheets_base_amount', 12, 2);
            $table->decimal('percent', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['logistician_id', 'week_monday']);
            $table->index('week_monday');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistician_payouts');
    }
};
