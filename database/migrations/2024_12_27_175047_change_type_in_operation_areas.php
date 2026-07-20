<?php

use App\Models\GeneralSettings\OperationArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        OperationArea::withTrashed()->each(function ($operationArea) {
            $operationArea->setTranslation('name','pt-pt', $operationArea->getRawOriginal('name'));
            $operationArea->setTranslation('name','en', $operationArea->getRawOriginal('name'));
            $operationArea->save();
        });
        Schema::table('operation_areas', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('operation_areas', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
