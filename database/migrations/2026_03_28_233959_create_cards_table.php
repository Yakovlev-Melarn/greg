<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCardsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sellerID');
            $table->foreign('sellerID')->references('id')->on('sellers');
            $table->integer('nmID');
            $table->integer('supplier');
            $table->string('supplierVendorCode')->nullable();
            $table->string('vendorCode')->nullable();
            $table->string('supplierName');
            $table->string('productName');
            $table->string('chrtID')->nullable();
            $table->string('photo')->nullable();
            $table->string('sku')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cards');
    }
}
