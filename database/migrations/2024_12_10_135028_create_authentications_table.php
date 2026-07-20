<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('authentications', function (Blueprint $table) {
            $table->id();
            $table->string('ip');
            $table->string('userAgent');
            $table->longText('token');
            $table->longText('rsa');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentications');
    }
};
