<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_warehouse_stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_warehouse_id')
                ->constrained('seller_warehouses')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('chrt_id');
            $table->unsignedInteger('amount')->default(0);
            $table->boolean('is_positive')->default(false);
            $table->timestamp('collected_at');
            $table->timestamp('last_sent_to_wb_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_warehouse_id', 'chrt_id'], 'swss_warehouse_chrt_unique');
        });

        Schema::create('seller_warehouse_stock_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_warehouse_id')
                ->constrained('seller_warehouses')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('chrt_id');
            $table->unsignedInteger('amount')->default(0);
            $table->boolean('is_positive')->default(false);
            $table->boolean('wb_eligible')->default(false);
            $table->boolean('included_in_wb_batch')->default(false);
            $table->timestamp('wb_sent_at')->nullable();
            $table->timestamp('collected_at');
            $table->uuid('run_key');
            $table->timestamps();

            $table->index(['seller_warehouse_id', 'collected_at'], 'swsh_warehouse_collected_idx');
            $table->index(['seller_warehouse_id', 'run_key'], 'swsh_warehouse_run_idx');
            $table->index(['seller_warehouse_id', 'chrt_id', 'collected_at'], 'swsh_warehouse_chrt_collected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_warehouse_stock_histories');
        Schema::dropIfExists('seller_warehouse_stock_snapshots');
    }
};
