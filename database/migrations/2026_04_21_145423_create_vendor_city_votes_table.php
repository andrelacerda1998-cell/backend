<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vendor_city_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Vendor::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\GeneralSettings\SurveyCity::class)
                ->constrained('survey_cities')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vendor_id', 'survey_city_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_city_votes');
    }
};
