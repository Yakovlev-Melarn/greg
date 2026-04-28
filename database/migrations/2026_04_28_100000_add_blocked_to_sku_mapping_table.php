<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skuMapping', function (Blueprint $table) {
            if (!Schema::hasColumn('skuMapping', 'blocked')) {
                $table->boolean('blocked')->default(0)->after('wbPrice');
                $table->index('blocked');
            }
        });
    }

    public function down(): void
    {
        Schema::table('skuMapping', function (Blueprint $table) {
            if (Schema::hasColumn('skuMapping', 'blocked')) {
                $table->dropIndex(['blocked']);
                $table->dropColumn('blocked');
            }
        });
    }
};
