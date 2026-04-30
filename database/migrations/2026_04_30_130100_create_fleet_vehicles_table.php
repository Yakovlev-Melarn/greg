<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_company_id')->nullable()->constrained('transport_companies')->nullOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->string('plate_number')->unique();
            $table->decimal('tonnage', 8, 2);
            $table->enum('ownership_type', ['owned', 'rented'])->default('owned');
            $table->decimal('rent_per_day', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicles');
    }
};
