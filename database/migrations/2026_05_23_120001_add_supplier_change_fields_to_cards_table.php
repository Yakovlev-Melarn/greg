<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            if (! Schema::hasColumn('cards', 'supplier_change_reason')) {
                $table->text('supplier_change_reason')->nullable()->after('supplierName');
            }
            if (! Schema::hasColumn('cards', 'supplier_changed_at')) {
                $table->timestamp('supplier_changed_at')->nullable()->after('supplier_change_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            if (Schema::hasColumn('cards', 'supplier_changed_at')) {
                $table->dropColumn('supplier_changed_at');
            }
            if (Schema::hasColumn('cards', 'supplier_change_reason')) {
                $table->dropColumn('supplier_change_reason');
            }
        });
    }
};
