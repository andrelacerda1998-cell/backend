<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('max_uses')->nullable();
            $table->unsignedTinyInteger('discount_percentage');
            $table->json('valid_services')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

