<?php

use App\Models\GeneralSettings\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->foreignIdFor(Document::class)->nullable()->after('vendor_id')->constrained()->cascadeOnDelete();
            $table->date('expiration_date')->nullable()->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn('document_id');
            $table->dropColumn('expiration_date');
            $table->string('type')->nullable()->after('vendor_id');
        });
    }
};
