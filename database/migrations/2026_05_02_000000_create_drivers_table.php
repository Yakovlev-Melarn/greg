<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('fleet_vehicle_id')
                ->nullable()
                ->unique()
                ->constrained('fleet_vehicles')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
