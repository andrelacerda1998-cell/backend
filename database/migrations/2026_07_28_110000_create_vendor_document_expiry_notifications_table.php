<?php

use App\Models\Vendor\VendorDocuments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registo de avisos de expiração já enviados por documento.
 *
 * Serve de garantia de idempotência do comando documents:notify-expiring.
 * A chave única (documento, data de expiração, limiar) é imposta pela BD, por isso
 * duas execuções no mesmo dia — ou em paralelo — nunca duplicam o mesmo aviso.
 * Incluir expiration_date na chave faz com que uma renovação (nova data no mesmo
 * registo) reinicie automaticamente a série de avisos, sem limpezas manuais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_document_expiry_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(VendorDocuments::class, 'vendor_document_id')->constrained('vendor_documents')->cascadeOnDelete();
            $table->date('expiration_date');
            $table->unsignedSmallInteger('days_before');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['vendor_document_id', 'expiration_date', 'days_before'],
                'vendor_doc_expiry_notif_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_document_expiry_notifications');
    }
};
