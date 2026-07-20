<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        try {
            Schema::create('payshop_payments_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignIdFor(\RwInteractive\PayshopSdk\PayshopSdk::$customerModel)->constrained();
                $table->string('uuid');
                $table->integer('amount');
                $table->boolean('paid');
                $table->string('status');
                $table->string('type');
                $table->integer('refunded');
                $table->string('service');
                $table->string('service_uuid');
                $table->string('token');
                $table->string('ip')->default('');
                $table->foreignId('payment_method_id')->nullable()->default(null)->references('id')->on('payshop_payment_methods');

                $table->timestamps();
            });
        }catch (Exception $exception){
            Schema::drop('payshop_payments_orders');

            throw $exception;
        }

    }

    public function down()
    {
        Schema::drop('payshop_payments_orders');
    }
};
