<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_warehouses', 'stock_supplier_ids')) {
                $table->json('stock_supplier_ids')->nullable()->after('supplier');
            }
            if (! Schema::hasColumn('seller_warehouses', 'sima_stock_via')) {
                $table->string('sima_stock_via', 20)->default('wb_catalog')->after('stock_supplier_ids');
            }
        });

        $rows = DB::table('seller_warehouses')->select('id', 'supplier')->get();
        foreach ($rows as $row) {
            $supplier = $row->supplier;
            if ($supplier === null || (int) $supplier === 0) {
                $ids = json_encode([10]);
                $via = 'wb_catalog';
            } else {
                $ids = json_encode([(int) $supplier]);
                $via = (int) $supplier === 20 ? 'sima_api' : 'wb_catalog';
            }
            DB::table('seller_warehouses')->where('id', $row->id)->update([
                'stock_supplier_ids' => $ids,
                'sima_stock_via' => $via,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('seller_warehouses')) {
            return;
        }

        Schema::table('seller_warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('seller_warehouses', 'sima_stock_via')) {
                $table->dropColumn('sima_stock_via');
            }
            if (Schema::hasColumn('seller_warehouses', 'stock_supplier_ids')) {
                $table->dropColumn('stock_supplier_ids');
            }
        });
    }
};
