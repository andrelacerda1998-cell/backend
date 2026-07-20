<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_allowed_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('allowed_zone_id')->constrained('allowed_zone')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vendor_id', 'allowed_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_allowed_zones');
    }
};
