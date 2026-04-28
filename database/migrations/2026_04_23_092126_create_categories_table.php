<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->unique(); // ID из внешнего источника
            $table->string('name', 255);
            $table->unsignedBigInteger('parent_id')->nullable(); // для связи с родительской категорией
            $table->string('parent_name', 255)->nullable();
            $table->boolean('checked')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
