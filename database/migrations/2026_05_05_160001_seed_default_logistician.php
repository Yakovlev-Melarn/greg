<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('logisticians')->exists()) {
            return;
        }

        DB::table('logisticians')->insert([
            'full_name' => 'Логист',
            'telegram' => null,
            'payout_start_date' => '2025-03-15',
            'payout_percent' => 5.00,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('logisticians')->where('full_name', 'Логист')->delete();
    }
};
