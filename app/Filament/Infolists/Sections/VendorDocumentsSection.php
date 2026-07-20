<?php

namespace App\Filament\Infolists\Sections;

use App\Filament\Infolists\TextEntries\VendorDocumentTextEntry;
use App\Models\Vendor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

class VendorDocumentsSection
{
    public static function make(): Section
    {
        return Section::make(__('backoffice/vendor.infolist.validation_documents'))
            ->description(fn(Vendor $record) => $record->all_documents_verified ? '' :
                __('backoffice/vendor.infolist.missing').$record->missing_documents
                    ->pluck('name')
                    ->join(', ')
            )
            ->extraAttributes(['class' => 'h-full'])
            ->icon(fn (Vendor $record) => $record->all_documents_verified ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle')
            ->iconColor(fn (Vendor $record) => $record->all_documents_verified ? 'success' : 'danger')
            ->columnSpan(1)
            ->headerActions([
                Action::make(__('backoffice/vendor.infolist.upload_documents'))
                    ->button()
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalHeading(__('backoffice/vendor.infolist.upload_documents_modal'))
                    ->form([
                        Select::make('document_id')
                            ->label(__('backoffice/vendor.infolist.documents.type'))
                            ->options(function (Vendor $record){
                                return $record->required_documents->pluck('name', 'id');
                            })
                            ->required(),
                        FileUpload::make('file')
                            ->label(__('backoffice/vendor.infolist.documents.file'))
                            ->required(),
                    ])
                    ->action(function (Vendor $record, array $data) {
                        $document = $record->documents()->create([
                            'document_id' => $data['document_id'],
                        ]);

                        $document->addMedia(public_path("storage/{$data['file']}"))->toMediaCollection();

                        if (file_exists(public_path("storage/{$data['file']}"))) {
                            unlink(public_path("storage/{$data['file']}"));
                        }

                        Notification::make()
                            ->title('Upload successfully')
                            ->success()
                            ->send();
                    }),
                Action::make(__('backoffice/vendor.infolist.history'))
                    ->icon('heroicon-o-inbox-stack')
                    ->color('gray')
                    ->hidden(fn (Vendor $record) => $record->documents()->count() == 0)
                    ->modalHeading(__('backoffice/vendor.infolist.history'))
                    ->modalContent(fn (Vendor $record) => view('filament.infolists.sections.vendor-documents.history', ['documents' => $record->documents->sortByDesc(['updated_at', 'created_at'])]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('backoffice/vendor.infolist.history_table.close'))
                    ->modalFooterActionsAlignment('right'),
            ])
            ->schema([
                RepeatableEntry::make('documents')
                    ->label(__('backoffice/vendor.infolist.documents.title'))
                    ->contained(false)
                    ->schema([
                        VendorDocumentTextEntry::make(function (Vendor\VendorDocuments $documents){
                            return $documents->type?->name;
                        }),
                    ]),
                RepeatableEntry::make('missing_documents')
                    ->label(__('backoffice/vendor.infolist.documents.missing_title'))
                    ->contained(false)
                    ->visible(fn (Vendor $record) => $record->missing_documents->isNotEmpty())
                    ->schema([
                        TextEntry::make('name')
                            ->hiddenLabel()
                            ->icon('heroicon-c-x-circle')
                            ->iconColor('danger')
                            ->color('danger')
                            ->tooltip(__('backoffice/vendor.infolist.document_missing')),
                    ]),
            ]);
    }
}
