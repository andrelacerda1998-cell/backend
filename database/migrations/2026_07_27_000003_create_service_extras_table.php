<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // time | part
            $table->string('description')->nullable();    // para peças/materiais
            $table->unsignedInteger('minutes')->nullable(); // para tempo extra
            $table->integer('amount')->default(0);        // cêntimos (o que o técnico recebe a mais)
            $table->string('status')->default('pending'); // pending | approved | rejected | withdrawn
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_extras');
    }
};
