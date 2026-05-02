<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->date('report_date');
            $table->decimal('work_hours', 8, 2)->nullable();
            $table->decimal('extra_work_hours', 8, 2)->nullable();
            $table->boolean('night_loading')->default(false);
            $table->decimal('night_loading_amount', 12, 2)->nullable();
            $table->boolean('manual_floor_lift')->default(false);
            $table->decimal('manual_floor_lift_amount', 12, 2)->nullable();
            $table->decimal('route_sheet_total', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'report_date']);
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_daily_reports');
    }
};
