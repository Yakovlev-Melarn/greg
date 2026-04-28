<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('samson_package_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('samson_products')->onDelete('cascade');
            $table->enum('type', ['height', 'width', 'depth']);
            $table->decimal('value', 8, 2);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('samson_package_sizes');
    }
};
