<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payshop_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\RwInteractive\PayshopSdk\PayshopSdk::$customerModel)->constrained();
            $table->string('type');
            $table->string('uuid')->nullable();
            $table->string('token')->nullable();
            $table->string('brand')->nullable();
            $table->string('country')->nullable();
            $table->string('holder')->nullable();
            $table->string('bin')->nullable();
            $table->string('last4')->nullable();
            $table->string('expire_month')->nullable();
            $table->string('expire_year')->nullable();
            $table->string('brand_description')->default("")->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::drop('payshop_payment_methods');
    }
};
