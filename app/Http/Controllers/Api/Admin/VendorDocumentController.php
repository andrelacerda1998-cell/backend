<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ApproveVendorDocumentRequest;
use App\Http\Requests\Api\Admin\DeclineVendorDocumentRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Vendor\VendorDocuments;
use App\Notifications\Vendor\Documents\AcceptNotification;
use App\Notifications\Vendor\Documents\DenyNotification;
use Illuminate\Http\Request;

/**
 * Revisão de documentos KYC dos técnicos — equivalente às ações "Verificar" /
 * "Recusar" do Filament (App\Filament\Infolists\TextEntries\VendorDocumentTextEntry).
 * Aprovar/recusar aqui faz exatamente o que o Filament faz, incluindo notificar
 * o técnico (email + push) — não é só leitura.
 */
class VendorDocumentController extends Controller
{
    /**
     * GET /v1/admin/vendor-documents — por omissão só os pendentes (fila de
     * revisão); ?status=approved|declined|pending para ver outros estados.
     */
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);
        $status = $request->string('status')->trim()->value() ?: 'pending';

        $query = VendorDocuments::query()
            ->with(['vendor.user', 'type'])
            ->where('status', $status)
            ->latest('created_at');

        $documents = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($documents->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function approve(ApproveVendorDocumentRequest $request, VendorDocuments $vendorDocument): ApiSuccessResponse|ApiErrorResponse
    {
        if ($vendorDocument->status !== 'pending') {
            return new ApiErrorResponse(null, 'Este documento já foi revisto.', 409);
        }

        $vendorDocument->update([
            'status' => 'approved',
            'expiration_date' => $request->validated('expiration_date'),
        ]);
        $vendorDocument->vendor->user->notify(new AcceptNotification($vendorDocument));

        return ApiSuccessResponse::make($this->present($vendorDocument->fresh(['vendor.user', 'type'])));
    }

    public function decline(DeclineVendorDocumentRequest $request, VendorDocuments $vendorDocument): ApiSuccessResponse|ApiErrorResponse
    {
        if ($vendorDocument->status !== 'pending') {
            return new ApiErrorResponse(null, 'Este documento já foi revisto.', 409);
        }

        $vendorDocument->update([
            'status' => 'declined',
            'reason' => $request->validated('reason'),
        ]);
        $vendorDocument->vendor->user->notify(new DenyNotification($vendorDocument));

        return ApiSuccessResponse::make($this->present($vendorDocument->fresh(['vendor.user', 'type'])));
    }

    private function present(VendorDocuments $document): array
    {
        // NÃO usar vendor->name / vendor->fullName -- ambos delegam para
        // user->full_name, que não existe no User (mesma família do bug já
        // encontrado em User::setNameAttribute() -- ver SystemProfitController).
        // first_name/last_name são as colunas reais.
        $user = $document->vendor->user;

        return [
            'id' => $document->id,
            'vendor_id' => $document->vendor_id,
            'vendor_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null,
            'document_type' => $document->type?->name,
            'status' => $document->status,
            'reason' => $document->reason,
            'expiration_date' => $document->expiration_date,
            // URL assinada temporária (5 min), igual ao Filament -- funciona com disco
            // local graças ao Storage::buildTemporaryUrlsUsing() no AppServiceProvider.
            'file_url' => $document->getFirstTemporaryUrl(now()->addMinutes(5)),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
