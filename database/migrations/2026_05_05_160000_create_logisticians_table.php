<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logisticians', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('telegram')->nullable();
            $table->date('payout_start_date')->default('2025-03-15');
            $table->decimal('payout_percent', 5, 2)->default(5.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logisticians');
    }
};
