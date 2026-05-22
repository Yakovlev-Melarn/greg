<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->timestamp('wb_created_at')->nullable();
            $table->index(['sellerID', 'wb_created_at'], 'cards_seller_wb_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex('cards_seller_wb_created_at_idx');
            $table->dropColumn('wb_created_at');
        });
    }
};
