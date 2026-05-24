<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sima_supplier_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('missing_mapping')->default(0);
            $table->unsignedInteger('sima_cheaper')->default(0);
            $table->unsignedInteger('not_on_wb')->default(0);
            $table->unsignedInteger('switched_to_wb')->default(0);
            $table->unsignedInteger('trashed')->default(0);
            $table->unsignedInteger('skipped_low_stock')->default(0);
            $table->unsignedInteger('wb_errors')->default(0);
            $table->unsignedInteger('skipped_other')->default(0);
            $table->string('job_id', 64)->unique();
            $table->string('log_path')->nullable();
            $table->boolean('force_reaudit')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sima_supplier_audit_runs');
    }
};
