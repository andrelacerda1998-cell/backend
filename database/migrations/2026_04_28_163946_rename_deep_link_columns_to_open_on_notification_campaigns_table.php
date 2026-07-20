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
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->renameColumn('deep_link_type', 'open_type');
            $table->renameColumn('deep_link_id', 'open_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->renameColumn('open_type', 'deep_link_type');
            $table->renameColumn('open_id', 'deep_link_id');
        });
    }
};
