<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->unsignedBigInteger('wb_warehouse_id');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'wb_warehouse_id']);
        });

        $hasColumn = Schema::hasColumn('sellers', 'wb_warehouse_id');
        if ($hasColumn) {
            $sellers = DB::table('sellers')->select('id', 'wb_warehouse_id')->get();
            foreach ($sellers as $seller) {
                DB::table('seller_warehouses')->insert([
                    'seller_id' => $seller->id,
                    'wb_warehouse_id' => $seller->wb_warehouse_id,
                    'name' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('sellers', function (Blueprint $table) {
                $table->dropColumn('wb_warehouse_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->unsignedBigInteger('wb_warehouse_id')->nullable()->after('name');
        });

        $warehouseRows = DB::table('seller_warehouses')
            ->orderBy('id')
            ->get()
            ->groupBy('seller_id');

        foreach ($warehouseRows as $sellerId => $rows) {
            $first = $rows->first();
            DB::table('sellers')->where('id', $sellerId)->update([
                'wb_warehouse_id' => $first->wb_warehouse_id,
            ]);
        }

        Schema::dropIfExists('seller_warehouses');
    }
};
