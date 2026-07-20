<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table(config('payshop-sdk.user_table'), function (Blueprint $table) {
            $table->string('pay_shop_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table(config('payshop-sdk.user_table'), function (Blueprint $table) {
            $table->dropColumn('pay_shop_id');
        });
    }
};
