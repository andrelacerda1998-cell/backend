<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        \App\Models\GeneralSettings\Gender::all()->each(function ($gender) {
            $gender->setTranslation('name', 'en', $gender->getRawOriginal('name'));
            $gender->save();
        });

        Schema::table('genders', function (Blueprint $table) {
            $table->json('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('genders', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
