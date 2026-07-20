<?php

use App\Models\Voucher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignIdFor(Voucher::class)->nullable()->after('amount')->constrained()->nullOnDelete();
            $table->integer('original_amount')->nullable()->after('voucher_id');
            $table->integer('discount_amount')->nullable()->after('original_amount');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropColumn(['voucher_id', 'original_amount', 'discount_amount']);
        });
    }
};

