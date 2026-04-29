<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('level', 20)->default('info');
            $table->string('source', 100)->nullable();
            $table->boolean('is_read')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['level', 'created_at']);
            $table->index(['is_read', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
