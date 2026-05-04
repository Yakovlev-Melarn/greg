<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_warehouses', 'stock_collect_enabled')) {
                $table->boolean('stock_collect_enabled')->default(false)->after('supplier');
            }
            if (! Schema::hasColumn('seller_warehouses', 'stock_send_to_wb')) {
                $table->boolean('stock_send_to_wb')->default(false)->after('stock_collect_enabled');
            }
            if (! Schema::hasColumn('seller_warehouses', 'stock_frequency_minutes')) {
                $table->unsignedSmallInteger('stock_frequency_minutes')->default(30)->after('stock_send_to_wb');
            }
            if (! Schema::hasColumn('seller_warehouses', 'stock_last_run_at')) {
                $table->timestamp('stock_last_run_at')->nullable()->index()->after('stock_frequency_minutes');
            }
            if (! Schema::hasColumn('seller_warehouses', 'stock_last_run_result')) {
                $table->json('stock_last_run_result')->nullable()->after('stock_last_run_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('seller_warehouses', 'stock_last_run_result')) {
                $table->dropColumn('stock_last_run_result');
            }
            if (Schema::hasColumn('seller_warehouses', 'stock_last_run_at')) {
                $table->dropColumn('stock_last_run_at');
            }
            if (Schema::hasColumn('seller_warehouses', 'stock_frequency_minutes')) {
                $table->dropColumn('stock_frequency_minutes');
            }
            if (Schema::hasColumn('seller_warehouses', 'stock_send_to_wb')) {
                $table->dropColumn('stock_send_to_wb');
            }
            if (Schema::hasColumn('seller_warehouses', 'stock_collect_enabled')) {
                $table->dropColumn('stock_collect_enabled');
            }
        });
    }
};
