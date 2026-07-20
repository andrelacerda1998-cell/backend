<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\CreateVendorDocumentRequest;
use App\Http\Requests\Api\Vendor\GetDocumentTypesRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\Document;
use App\Models\User;
use App\Models\Vendor\VendorDocuments;
use Exception;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class DocumentController extends Controller
{
    public function index()
    {
        $documents = VendorDocuments::where('vendor_id', auth()->user()->vendor->id)->get();

        return new ApiSuccessResponse(compact('documents'));
    }

    public function show(VendorDocuments $document)
    {
        if ($document->vendor_id !== auth()->user()->vendor->id) {
            return new ApiErrorResponse(new Exception, 'Document not found', 404);
        }

        return new ApiSuccessResponse($document);
    }

    public function types()
    {

        $documents = Document::where('required', true)->get();

        return new ApiSuccessResponse(['types' => $documents->transform(fn ($document) => [
            'id' => $document->id,
            'name' => $document->name,
            'description' => $document->description,
        ])]);
    }

    /**
     * @return ApiErrorResponse|ApiSuccessResponse
     */
    public function store(CreateVendorDocumentRequest $request)
    {
        try {
            DB::beginTransaction();
            $document = VendorDocuments::create([
                'vendor_id' => auth()->user()->vendor->id,
                'document_id' => $request->get('type'),
            ]);

            $document->addMediaFromRequest('document')->toMediaCollection();

            DB::commit();

            Notification::make()
                ->title(new HtmlString('Vendor '.auth()->user()->name.' just added a '.$document->type->name.' document'))
                ->warning()
                ->actions([
                    Action::make('view')
                        ->button()
                        ->url(route('filament.backoffice.resources.vendors.view', auth()->user()->vendor))
                        ->markAsRead(),
                ])
                ->sendToDatabase(User::whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['admin', 'super-admin']))->get());

            return new ApiSuccessResponse(compact('document'));
        } catch (Exception $e) {
            DB::rollBack();

            return new ApiErrorResponse($e, 500);
        }
    }
}
