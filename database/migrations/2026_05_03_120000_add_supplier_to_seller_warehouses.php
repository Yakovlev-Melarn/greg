<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_warehouses', 'supplier')) {
                $table->unsignedSmallInteger('supplier')->nullable()->after('wb_warehouse_id');
                $table->index(['seller_id', 'supplier'], 'seller_warehouses_seller_id_supplier_index');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('seller_warehouses', 'supplier')) {
                $table->dropIndex('seller_warehouses_seller_id_supplier_index');
                $table->dropColumn('supplier');
            }
        });
    }
};
