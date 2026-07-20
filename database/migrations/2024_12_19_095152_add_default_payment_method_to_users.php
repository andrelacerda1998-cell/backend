<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_payment_method_id')
                ->nullable()
                ->default(null)
                ->after('language')
                ->constrained('payshop_payment_methods');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(\RwInteractive\PayshopSdk\Models\PaymentMethod::class);
            $table->dropColumn('payment_method_id');
        });
    }
};
