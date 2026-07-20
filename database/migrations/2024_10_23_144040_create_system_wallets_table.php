<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('System Wallet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_wallets');
    }
};
