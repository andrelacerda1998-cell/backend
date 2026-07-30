<?php

use App\Http\Controllers\Api\Admin\AllowedZoneController;
use App\Http\Controllers\Api\Admin\AuditController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\DocumentController;
use App\Http\Controllers\Api\Admin\FeeSettingsController;
use App\Http\Controllers\Api\Admin\OperationAreaController;
use App\Http\Controllers\Api\Admin\ServicesTypeController;
use App\Http\Controllers\Api\Admin\SystemProfitController;
use App\Http\Controllers\Api\Admin\VendorController;
use App\Http\Controllers\Api\Admin\VendorDocumentController;
use App\Http\Controllers\Api\Admin\VendorPaymentController;
use App\Http\Controllers\Api\Admin\VoucherController;
use Illuminate\Support\Facades\Route;

// Consumida pelo backoffice Next.js (piquet-backoffice), servidor-a-servidor —
// ver App\Http\Middleware\AdminApiToken. Nunca exposta ao browser diretamente.
Route::group(['prefix' => 'admin', 'middleware' => 'admin.api'], function () {
    Route::get('/fee-settings', [FeeSettingsController::class, 'show']);
    Route::put('/fee-settings', [FeeSettingsController::class, 'update']);

    Route::get('/system-profit', [SystemProfitController::class, 'index']);

    // apiResource já só regista index/store/show/update/destroy (sem create/edit).
    Route::apiResource('vouchers', VoucherController::class);

    // Revisão de documentos KYC dos vendors — equivalente às ações do Filament
    // em VendorDocumentTextEntry (Verificar/Recusar).
    Route::get('/vendor-documents', [VendorDocumentController::class, 'index']);
    Route::put('/vendor-documents/{vendorDocument}/approve', [VendorDocumentController::class, 'approve']);
    Route::put('/vendor-documents/{vendorDocument}/decline', [VendorDocumentController::class, 'decline']);

    // Pagamentos a vendors — equivalente ao Filament VendorPayments (ledger
    // interno + email/notificação; a transferência bancária é manual).
    Route::get('/vendor-payments', [VendorPaymentController::class, 'index']);
    Route::put('/vendor-payments/{vendor}/pay', [VendorPaymentController::class, 'pay']);

    // Clientes — equivalente ao Filament CustomerResource. Bloquear/Reativar
    // usam soft-delete real (ver nota no controller); sem ForceDelete, reset
    // de password ou impersonação nesta fatia (decisão explícita).
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::put('/customers/{id}/block', [CustomerController::class, 'block']);
    Route::put('/customers/{id}/restore', [CustomerController::class, 'restore']);

    // Aba "Visão geral" -- só indicadores calculáveis com dados reais
    // (bySource/retention devolvem vazio de propósito, ver CustomerController).
    Route::get('/customers/metrics', [CustomerController::class, 'metrics']);
    Route::get('/customers/by-location', [CustomerController::class, 'byLocation']);
    Route::get('/customers/by-source', [CustomerController::class, 'bySource']);
    Route::get('/customers/trend', [CustomerController::class, 'trend']);
    Route::get('/customers/retention', [CustomerController::class, 'retention']);

    // Técnicos — equivalente ao Filament VendorResource. Suspender/Reativar
    // usam soft-delete real, mas SEM a restrição de super-admin do Filament
    // (ver nota extensa no VendorController) -- decisão explícita do
    // utilizador.
    Route::get('/vendors', [VendorController::class, 'index']);
    Route::put('/vendors/{id}/suspend', [VendorController::class, 'suspend']);
    Route::put('/vendors/{id}/restore', [VendorController::class, 'restore']);

    // Aba "Visão geral" -- indicadores reais (sem avgApprovalTime, ver nota
    // em VendorController::metrics()).
    Route::get('/vendors/metrics', [VendorController::class, 'metrics']);
    Route::get('/vendors/by-category', [VendorController::class, 'byCategory']);
    Route::get('/vendors/by-location', [VendorController::class, 'byLocation']);
    Route::get('/vendors/top', [VendorController::class, 'top']);
    Route::get('/vendors/coverage', [VendorController::class, 'coverage']);

    // Catálogo (tipos de serviço) + Categorias — equivalentes ao Filament
    // ServicesTypeResource/OperationAreaResource. Só Lista + criar/editar
    // (sem apagar) e sem Zonas/AllowedZone -- decisão explícita do utilizador
    // (2026-07-29, ver notas nos controllers).
    Route::get('/services-types', [ServicesTypeController::class, 'index']);
    Route::post('/services-types', [ServicesTypeController::class, 'store']);
    Route::put('/services-types/{servicesType}', [ServicesTypeController::class, 'update']);

    Route::get('/operation-areas', [OperationAreaController::class, 'index']);
    Route::post('/operation-areas', [OperationAreaController::class, 'store']);
    Route::put('/operation-areas/{operationArea}', [OperationAreaController::class, 'update']);

    // Zonas — equivalente ao Filament AllowedZoneResource. Lista + criar/
    // editar, sem apagar (não é soft-delete) e sem a restrição de
    // super-admin do Filament -- decisões explícitas do utilizador
    // (2026-07-29, ver notas no controller).
    Route::get('/allowed-zones', [AllowedZoneController::class, 'index']);
    Route::post('/allowed-zones', [AllowedZoneController::class, 'store']);
    Route::put('/allowed-zones/{allowedZone}', [AllowedZoneController::class, 'update']);

    // Documentos — equivalente ao Filament DocumentResource. Lista + criar/
    // editar, sem apagar (mesma decisão de Catálogo/Categorias/Zonas).
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::put('/documents/{document}', [DocumentController::class, 'update']);

    // Atividade — feed real de auditoria (tabela audits), só leitura, sem
    // equivalente direto no Filament (lá é por registo). Filtrado a ações
    // de staff (admin/super-admin) -- ver nota no controller.
    Route::get('/audits', [AuditController::class, 'index']);
});
