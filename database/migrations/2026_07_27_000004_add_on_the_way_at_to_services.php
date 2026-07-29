<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca "a caminho" sem tocar no enum de status
     * (a app do cliente continua a ver Accepted/Arrived/Finished).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('on_the_way_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('on_the_way_at');
        });
    }
};
