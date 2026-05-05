<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_daily_reports', function (Blueprint $table) {
            $table->foreignId('fleet_vehicle_id')
                ->nullable()
                ->after('driver_id')
                ->constrained('fleet_vehicles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_daily_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_vehicle_id');
        });
    }
};
