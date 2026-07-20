<?php

use App\Models\GeneralSettings\OperationArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            $table->foreignIdFor(OperationArea::class)->after('time')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            $table->dropForeignIdFor(OperationArea::class);
        });
    }
};
