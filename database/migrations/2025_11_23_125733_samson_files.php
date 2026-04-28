<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('samson_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('samson_products')->onDelete('cascade');
            $table->string('url');
            $table->enum('type', ['photo', 'certificate', 'document']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('samson_files');
    }
};
