<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSkuMappingTable extends Migration
{
    public function up()
    {
        Schema::create('skuMapping', function (Blueprint $table) {
            $table->id();
            $table->string('origSku')->unique();
            $table->string('wbSku')->unique();
            $table->float('purchase_price')->nullable();
            $table->float('logistics_cost')->nullable();
            $table->float('selling_price')->nullable();
            $table->float('total_cost')->nullable();
            $table->float('wb_commission')->nullable();
            $table->float('fulfillment_cost')->default(53);
            $table->float('tax')->nullable();
            $table->float('net_profit')->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->float('depth')->nullable();
            $table->float('length')->nullable();
            $table->float('width')->nullable();
            $table->float('weight_kg')->nullable();
            $table->float('wbPrice')->nullable();
            $table->boolean('needUpdatePrice')->default(1);
            $table->boolean('needUpdateStock')->default(1);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('skuMapping');
    }
}

