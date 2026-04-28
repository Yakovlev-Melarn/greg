<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('samson_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->text('name');
            $table->text('name_1c')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('vendor_code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->text('description_ext')->nullable();
            $table->double('weight')->nullable();
            $table->double('volume')->nullable();
            $table->integer('nds')->nullable();
            $table->boolean('ban_not_multiple')->default(0);
            $table->boolean('out_of_stock')->default(0);
            $table->date('remove_date')->nullable();
            $table->integer('expiration_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('samson_products');
    }
};
