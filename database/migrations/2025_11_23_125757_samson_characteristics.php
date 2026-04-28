<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('samson_characteristics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('samson_products')->onDelete('cascade');
            $table->string('name');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('samson_characteristics');
    }
};
