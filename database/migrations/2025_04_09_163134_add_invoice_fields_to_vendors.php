<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('invoice_workspace')->default('')->after('iban');
            $table->string('auth_token')->default(null)->nullable()->after('invoice_workspace');
            $table->string('company_name')->default('')->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('invoice_workspace');
            $table->dropColumn('auth_token');
            $table->dropColumn('company_name');
        });
    }
};
