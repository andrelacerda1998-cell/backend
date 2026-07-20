<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        \App\Models\GeneralSettings\ServicesType::withTrashed()->each(function ($serviceType) {
            if (json_validate($serviceType->getRawOriginal('name'))){
                return;
            }
           $serviceType->setTranslation('name', 'en', $serviceType->getRawOriginal('name'));
           $serviceType->setTranslation('name', 'pt-pt', $serviceType->getRawOriginal('name'));

           $serviceType->setTranslation('description', 'en', $serviceType->getRawOriginal('description'));
           $serviceType->setTranslation('description', 'pt-pt', $serviceType->getRawOriginal('description'));
           $serviceType->save();
        });
        Schema::table('services_types', function (Blueprint $table) {
            $table->json('name')->nullable()->change();
            $table->json('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            //
        });
    }
};
