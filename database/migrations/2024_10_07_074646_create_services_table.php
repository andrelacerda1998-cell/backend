<?php

use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, 'customer_id')->nullable()->constrained('users', 'id')->nullOnDelete();
            $table->foreignIdFor(Vendor::class)->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->foreignIdFor(ServicesType::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Address::class)->nullable()->constrained()->nullOnDelete();
            $table->string('distance')->nullable()->default('');
            $table->text('customer_notes')->nullable();
            $table->text('vendor_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
