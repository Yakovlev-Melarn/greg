<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
//eyJhbGciOiJFUzI1NiIsImtpZCI6IjIwMjYwMzAydjEiLCJ0eXAiOiJKV1QifQ.eyJhY2MiOjMsImVudCI6MSwiZXhwIjoxNzkyODgxMDUwLCJmb3IiOiJzZWxmIiwiaWQiOiIwMTlkYzQzMS0yYmM3LTdlNzAtOTE5ZC03YTZjMjlhYWFiNGIiLCJpaWQiOjMxMTU5MjgyNywib2lkIjoyNTAxMTk0ODEsInMiOjgxNjYyLCJzaWQiOiI1MTkxOTNlMC1kNjU2LTQ4NDAtOTRlOS0yYTNmYjE2ZDUwN2MiLCJ0IjpmYWxzZSwidWlkIjozMTE1OTI4Mjd9.I9j8OT6Z7uw1IT6zPHWkWHQgRqOV_4Na6Dstbm_QLuu-1GZy4KBT_mC2EtrmRWtA-fPder7gGc2x58y3J3Vzag
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('wb_warehouse_id');
            $table->string('wb_api_key');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
