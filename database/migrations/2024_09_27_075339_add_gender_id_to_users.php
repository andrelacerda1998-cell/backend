<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\GeneralSettings\Gender::class)->nullable()->after('nif')->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\GeneralSettings\Gender::class);
            $table->dropColumn('gender_id');
        });
    }
};
