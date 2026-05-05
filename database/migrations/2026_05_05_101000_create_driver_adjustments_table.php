<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('adjustment_type', 20); // bonus | penalty
            $table->date('event_date');
            $table->decimal('total_amount', 12, 2);
            $table->text('comment');
            $table->string('status', 20)->default('open'); // open | closed
            $table->unsignedInteger('attachments_count')->default(0);
            $table->timestamps();

            $table->index(['driver_id', 'event_date']);
            $table->index(['adjustment_type', 'status', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_adjustments');
    }
};
