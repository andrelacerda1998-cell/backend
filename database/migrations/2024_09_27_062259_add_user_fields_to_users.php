<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nif')->nullable()->after('name');
            $table->string('phone_number')->nullable()->after('email_verified_at');
            $table->string('phone_number_verified_at')->nullable()->after('phone_number');
            $table->date('date_birthday')->nullable()->after('password');
            $table->string('language')->nullable()->after('date_birthday');
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nif');
            $table->dropColumn('phone_number');
            $table->dropColumn('phone_number_verified_at');
            $table->dropColumn('date_birthday');
            $table->dropColumn('language');
        });
    }
};
