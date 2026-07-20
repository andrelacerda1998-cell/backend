<?php

use App\Models\GeneralSettings\OperationArea;
use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_area_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(OperationArea::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Vendor::class)->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_area_vendors');
    }
};
