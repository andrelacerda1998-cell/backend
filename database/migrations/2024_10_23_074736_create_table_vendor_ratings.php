<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Vendor::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\GeneralSettings\OperationArea::class)->constrained()->cascadeOnDelete();
            $table->integer('average_rating');
            $table->integer('total_ratings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ratings');
    }
};
