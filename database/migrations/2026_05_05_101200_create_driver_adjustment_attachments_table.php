<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_adjustment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_adjustment_id')->constrained('driver_adjustments')->cascadeOnDelete();
            $table->string('disk', 50)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('driver_adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_adjustment_attachments');
    }
};
