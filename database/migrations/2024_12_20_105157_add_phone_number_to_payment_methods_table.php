<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payshop_payment_methods', function (Blueprint $table) {
            $table->string('phone_number')->after('last4')->nullable();
        });
    }

    public function down()
    {
        Schema::table('payshop_payment_methods', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
};
