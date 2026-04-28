<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_queues', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique(); // sku = $productData['id']
            $table->string('prefix')->nullable(); // prefix из $this->data['prefix']
            $table->integer('price')->nullable(); // prefix из $this->data['prefix']
            $table->boolean('blocked')->default(0);
            $table->timestamps();

            // Индекс для быстрого поиска по sku
            $table->index('sku');
            $table->index('blocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_queues');
    }
};
