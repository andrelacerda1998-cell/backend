<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services_type_vendor', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Vendor::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\GeneralSettings\ServicesType::class)->constrained()->cascadeOnDelete();
            $table->integer('hourly_rate');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services_type_vendor');
    }
};
